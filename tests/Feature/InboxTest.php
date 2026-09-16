<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Anaf\MessageType;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\BuyerMessageReceived;
use AtlasFlow\EFacturaRo\Laravel\Events\InvoiceReceived;
use AtlasFlow\EFacturaRo\Laravel\Facades\EFactura;
use AtlasFlow\EFacturaRo\Laravel\Jobs\PollSubmission;
use AtlasFlow\EFacturaRo\Laravel\Jobs\SyncInbox;
use AtlasFlow\EFacturaRo\Laravel\Models\InboxMessage;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Laravel\Services\Inbox;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Authorised;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Invoices;
use AtlasFlow\EFacturaRo\Support\Cui;
use AtlasFlow\EFacturaRo\Ubl\UblWriter;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-16 10:00:00');
    Authorised::for();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function pageJson(array $messages, int $page = 1, int $totalPages = 1): string
{
    return (string) json_encode([
        'mesaje' => $messages,
        'numar_inregistrari_in_pagina' => count($messages),
        'numar_total_inregistrari_per_pagina' => 500,
        'numar_total_inregistrari' => count($messages),
        'numar_total_pagini' => $totalPages,
        'index_pagina_curenta' => $page,
        'serial' => 'X',
        'cui' => '12345674',
        'titlu' => 'Lista Mesaje',
    ]);
}

it('sweeps the paginated list, stores rows once, and parses received invoices into events', function () {
    Event::fake([InvoiceReceived::class, BuyerMessageReceived::class]);
    $received = (new UblWriter)->write(Invoices::standard('SUP-2026-9'));

    Http::fake([
        'api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura*' => Http::sequence()
            ->push(pageJson([
                ['data_creare' => '202609151452', 'cif' => '12345674', 'id_solicitare' => '5001130200', 'detalii' => 'Factura cu id_incarcare=5001130200 emisa de cif_emitent=11223342 pentru cif_beneficiar=12345674', 'tip' => 'FACTURA PRIMITA', 'id' => '3001293500'],
            ], 1, 2))
            ->push(pageJson([
                ['data_creare' => '202609151700', 'cif' => '12345674', 'id_solicitare' => '5001130147', 'detalii' => 'Mesaj cumparator', 'tip' => 'MESAJ CUMPARATOR PRIMIT', 'id' => '3001293600'],
            ], 2, 2))
            ->push(pageJson([
                ['data_creare' => '202609151452', 'cif' => '12345674', 'id_solicitare' => '5001130200', 'detalii' => 'again', 'tip' => 'FACTURA PRIMITA', 'id' => '3001293500'],
            ])),
        'api.anaf.ro/test/FCTEL/rest/descarcare?id=3001293500' => Http::response(zipBundle(['5001130200.xml' => $received, 'semnatura_5001130200.xml' => '<Signature/>']), 200, ['Content-Type' => 'application/zip']),
        'api.anaf.ro/test/FCTEL/rest/descarcare?id=3001293600' => Http::response(zipBundle(['3001293600.xml' => '<Rasp/>', 'semnatura_3001293600.xml' => '<Signature/>']), 200, ['Content-Type' => 'application/zip']),
    ]);

    $stored = EFactura::syncInbox(Cui::of('12345674'));

    expect($stored)->toBe(2)
        ->and(InboxMessage::query()->count())->toBe(2);

    $invoice = InboxMessage::query()->where('anaf_id', '3001293500')->firstOrFail();

    expect($invoice->type)->toBe(MessageType::INVOICE_RECEIVED)
        ->and($invoice->seller_cui)->toBe('11223342')
        ->and($invoice->buyer_cui)->toBe('12345674')
        ->and($invoice->document_number)->toBe('SUP-2026-9')
        ->and($invoice->document_date?->toDateString())->toBe('2026-09-10')
        ->and($invoice->document_currency)->toBe('RON')
        ->and((string) $invoice->document_total)->toBe('1633.50')
        ->and($invoice->bundle_path)->toBe('efactura/12345674/inbox/3001293500')
        ->and($invoice->parsed_at)->not->toBeNull();

    Event::assertDispatched(InvoiceReceived::class, fn ($e) => $e->document->number === 'SUP-2026-9' && $e->message->is($invoice));
    Event::assertDispatched(BuyerMessageReceived::class);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'listaMesajePaginatieFactura') && str_contains($r->url(), 'cif=12345674&pagina=1'));

    // A second sweep sees the same row and stores nothing new.
    expect(EFactura::syncInbox(Cui::of('12345674')))->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(2);
});

it('nudges a processing submission when the list says it has resolved', function () {
    Queue::fake();
    Http::fake([
        'api.anaf.ro/test/FCTEL/rest/listaMesajePaginatieFactura*' => Http::response(pageJson([
            ['data_creare' => '202609151452', 'cif' => '12345674', 'id_solicitare' => '5001130147', 'detalii' => 'Factura cu id_incarcare=5001130147', 'tip' => 'FACTURA TRIMISA', 'id' => '3001293434'],
        ])),
    ]);

    $submission = Submission::query()->create([
        'cui' => '12345674', 'standard' => 'UBL', 'document_type' => '380', 'document_number' => 'PV-1',
        'xml' => '<x/>', 'b2c' => false, 'upload_options' => [], 'phase' => SubmissionPhase::PROCESSING, 'attempts' => 3,
        'upload_index' => 5001130147, 'next_poll_at' => CarbonImmutable::now()->addHours(2),
    ]);

    EFactura::syncInbox(Cui::of('12345674'));

    expect($submission->refresh()->download_id)->toBe('3001293434')
        ->and($submission->next_poll_at?->toDateTimeString())->toBe('2026-09-16 10:00:00');
    Queue::assertPushed(PollSubmission::class, fn ($job) => $job->submissionId === $submission->id);
});

it('the SyncInbox job releases itself when ANAF is down', function () {
    Http::fake(['api.anaf.ro/*' => Http::response('', 503)]);

    $job = new SyncInbox('12345674');
    $released = false;
    $job->setJob(new class($released) extends SyncJob
    {
        public function __construct(private bool &$flag)
        {
            parent::__construct(app(), '{}', 'sync', 'default');
        }

        public function release($delay = 0): void
        {
            $this->flag = true;
        }

        public function attempts(): int
        {
            return 1;
        }
    });

    $job->handle(app(Inbox::class));

    expect($released)->toBeTrue()
        ->and(InboxMessage::query()->count())->toBe(0);
});

it('efactura:sync-inbox queues one job per authorised CUI or the one given', function () {
    Queue::fake();
    Authorised::for('40000000');

    $this->artisan('efactura:sync-inbox')->assertSuccessful();
    Queue::assertPushed(SyncInbox::class, 2);

    $this->artisan('efactura:sync-inbox', ['cui' => 'RO11223342'])->assertSuccessful();
    Queue::assertPushed(SyncInbox::class, fn ($job) => $job->cui === '11223342');
});
