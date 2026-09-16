<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Anaf\UploadOptions;
use AtlasFlow\EFacturaRo\Laravel\Contracts\BundleStore;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionAbandoned;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionAccepted;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionDeferred;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionRefused;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionRejected;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\DocumentInvalid;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\NotAuthorised;
use AtlasFlow\EFacturaRo\Laravel\Facades\EFactura;
use AtlasFlow\EFacturaRo\Laravel\Jobs\PollSubmission;
use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Laravel\Services\Submitter;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Authorised;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Invoices;
use AtlasFlow\EFacturaRo\Ubl\UblWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-16 10:00:00');
    Authorised::for();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Runs a PollSubmission handler inline — Bus::dispatchSync would hand a ShouldQueue job to the (faked) queue instead. */
function runPoll(int $submissionId): void
{
    app()->call([new PollSubmission($submissionId), 'handle']);
}

function anafHappyPath(string $stare = 'stare-ok.xml', ?string $payload = null): void
{
    $payload ??= (new UblWriter)->write(Invoices::standard());

    Http::fake([
        'webservicesp.anaf.ro/prod/FCTEL/rest/validare/*' => Http::response(anafFixture('validare-ok.json')),
        'api.anaf.ro/test/FCTEL/rest/upload*' => Http::response(anafFixture('upload-ok.xml'), 200, ['Content-Type' => 'application/xml']),
        'api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response(anafFixture($stare), 200, ['Content-Type' => 'application/xml']),
        'api.anaf.ro/test/FCTEL/rest/descarcare*' => Http::response(zipBundle(['5001130147.xml' => $payload, 'semnatura_5001130147.xml' => '<Signature/>']), 200, ['Content-Type' => 'application/zip']),
    ]);
}

it('submits, polls to accepted, stores the bundle and raises SubmissionAccepted', function () {
    Event::fake([SubmissionAccepted::class, SubmissionDeferred::class]);
    anafHappyPath();

    $submission = EFactura::submit(Invoices::standard());
    $submission->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::ACCEPTED)
        ->and($submission->cui)->toBe('12345674')
        ->and($submission->standard)->toBe('UBL')
        ->and($submission->document_number)->toBe('PV-2026-000123')
        ->and($submission->upload_index)->toBe(5001130147)
        ->and($submission->download_id)->toBe('1234')
        ->and($submission->attempts)->toBe(1)
        ->and($submission->next_poll_at)->toBeNull()
        ->and($submission->resolved_at)->not->toBeNull()
        ->and($submission->bundle_path)->toBe('efactura/12345674/submissions/5001130147')
        ->and(app(BundleStore::class)->retrieve($submission->bundle_path)->signatureXml)->toBe('<Signature/>');

    Event::assertDispatched(SubmissionAccepted::class, fn ($e) => $e->submission->is($submission) && $e->bundle->isInvoice());
    Event::assertNotDispatched(SubmissionDeferred::class);

    Http::assertSentInOrder([
        fn ($r) => str_contains($r->url(), '/validare/FACT1'),
        fn ($r) => str_contains($r->url(), '/upload?standard=UBL&cif=12345674') && $r->hasHeader('Authorization', 'Bearer access-12345674') && str_contains($r->body(), '<cbc:CustomizationID>'),
        fn ($r) => str_contains($r->url(), '/stareMesaj?id_incarcare=5001130147'),
        fn ($r) => str_contains($r->url(), '/descarcare?id=1234'),
    ]);
});

it('stores ANAF\'s error messages and raises SubmissionRejected on nok', function () {
    Event::fake([SubmissionRejected::class, SubmissionAccepted::class]);
    anafHappyPath('stare-nok.xml', '<header xmlns="mfp:anaf:dgti:efactura:stareMesajFactura:v1"><Error errorMessage="BR-CO-10 failed"/><Error errorMessage="BR-RO-110 failed"/></header>');

    $submission = EFactura::submit(Invoices::standard())->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::REJECTED)
        ->and($submission->last_error)->toBe(['BR-CO-10 failed', 'BR-RO-110 failed'])
        ->and($submission->bundle_path)->not->toBeNull();

    Event::assertDispatched(SubmissionRejected::class, fn ($e) => $e->messages === ['BR-CO-10 failed', 'BR-RO-110 failed']);
    Event::assertNotDispatched(SubmissionAccepted::class);
});

it('keeps polling while ANAF is still processing, on the backoff schedule', function () {
    Queue::fake();
    anafHappyPath('stare-processing.xml');

    $submission = EFactura::submit(Invoices::standard())->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::PROCESSING)
        ->and($submission->next_poll_at?->toDateTimeString())->toBe('2026-09-16 10:00:30');
    Queue::assertPushed(PollSubmission::class, fn ($job) => $job->submissionId === $submission->id && $job->delay === 30);

    runPoll($submission->id);
    $submission->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::PROCESSING)
        ->and($submission->attempts)->toBe(1)
        ->and($submission->next_poll_at?->toDateTimeString())->toBe('2026-09-16 10:01:00');
    Queue::assertPushed(PollSubmission::class, fn ($job) => $job->delay === 60);
});

it('marks the submission refused with ANAF\'s reason when the upload is rejected outright', function () {
    Event::fake([SubmissionRefused::class]);
    Http::fake([
        'webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json')),
        'api.anaf.ro/test/FCTEL/rest/upload*' => Http::response(anafFixture('upload-refused-too-large.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);

    $submission = EFactura::submit(Invoices::standard())->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::REFUSED)
        ->and($submission->last_error)->toBe(['Marime fisier transmis mai mare de 10 MB.'])
        ->and($submission->upload_index)->toBeNull();

    Event::assertDispatched(SubmissionRefused::class);
});

it('defers instead of failing when ANAF is down, and PollSubmission finishes the upload later', function () {
    Event::fake([SubmissionDeferred::class]);
    Queue::fake();
    Http::fake([
        'webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json')),
        'api.anaf.ro/test/FCTEL/rest/upload*' => Http::sequence()
            ->push('<html>502 Bad Gateway</html>', 502)
            ->push(anafFixture('upload-ok.xml'), 200, ['Content-Type' => 'application/xml']),
        'api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::response(anafFixture('stare-processing.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);

    $submission = EFactura::submit(Invoices::standard())->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::PENDING_UPLOAD)
        ->and($submission->next_poll_at?->toDateTimeString())->toBe('2026-09-16 10:05:00')
        ->and($submission->last_error['deferred'] ?? null)->toContain('502');
    Event::assertDispatched(SubmissionDeferred::class);
    Queue::assertPushed(PollSubmission::class, fn ($job) => $job->delay === 300);

    CarbonImmutable::setTestNow('2026-09-16 10:05:00');
    runPoll($submission->id);
    $submission->refresh();

    expect($submission->phase)->toBe(SubmissionPhase::PROCESSING)
        ->and($submission->upload_index)->toBe(5001130147)
        ->and($submission->last_error)->toBeNull();
});

it('efactura:poll sweeps due submissions and, on the sync queue, drives them to the end', function () {
    anafHappyPath();
    $xml = (new UblWriter)->write(Invoices::standard());

    $due = Submission::query()->create([
        'cui' => '12345674', 'standard' => 'UBL', 'document_type' => '380', 'document_number' => 'PV-2026-000123',
        'xml' => $xml, 'b2c' => false, 'upload_options' => [], 'phase' => SubmissionPhase::PENDING_UPLOAD, 'attempts' => 0,
        'next_poll_at' => CarbonImmutable::now()->subMinute(),
    ]);
    $notDue = Submission::query()->create([
        'cui' => '12345674', 'standard' => 'UBL', 'document_type' => '380', 'document_number' => 'PV-2026-000124',
        'xml' => $xml, 'b2c' => false, 'upload_options' => [], 'phase' => SubmissionPhase::PENDING_UPLOAD, 'attempts' => 0,
        'next_poll_at' => CarbonImmutable::now()->addHour(),
    ]);

    $this->artisan('efactura:poll')->expectsOutputToContain('1 submission(s) polled')->assertSuccessful();

    expect($due->refresh()->phase)->toBe(SubmissionPhase::ACCEPTED)
        ->and($notDue->refresh()->phase)->toBe(SubmissionPhase::PENDING_UPLOAD);
});

it('defers a poll when ANAF answers 5xx and abandons after the configured attempts', function () {
    Event::fake([SubmissionDeferred::class, SubmissionAbandoned::class]);
    Queue::fake();
    config()->set('efactura.submissions.max_attempts', 2);
    Http::fake([
        'webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json')),
        'api.anaf.ro/test/FCTEL/rest/upload*' => Http::response(anafFixture('upload-ok.xml'), 200, ['Content-Type' => 'application/xml']),
        'api.anaf.ro/test/FCTEL/rest/stareMesaj*' => Http::sequence()
            ->push('', 503)
            ->push(anafFixture('stare-processing.xml'), 200, ['Content-Type' => 'application/xml'])
            ->push(anafFixture('stare-processing.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);

    $submission = EFactura::submit(Invoices::standard());
    $poll = fn () => runPoll($submission->id);

    $poll();
    expect($submission->refresh()->phase)->toBe(SubmissionPhase::PROCESSING)->and($submission->attempts)->toBe(0);
    Event::assertDispatched(SubmissionDeferred::class);

    $poll();
    $poll();
    expect($submission->refresh()->attempts)->toBe(2)->and($submission->phase)->toBe(SubmissionPhase::PROCESSING);

    $poll();
    expect($submission->refresh()->phase)->toBe(SubmissionPhase::ABANDONED);
    Event::assertDispatched(SubmissionAbandoned::class);
});

it('refuses a document that fails local validation before touching ANAF', function () {
    Http::fake();

    try {
        EFactura::submit(Invoices::standard(payable: '1633.51'));
        $this->fail('expected DocumentInvalid');
    } catch (DocumentInvalid $e) {
        expect($e->result->codes())->toBe(['BR-CO-16'])
            ->and($e->getMessage())->toContain('local validation');
    }

    Http::assertNothingSent();
    expect(Submission::query()->count())->toBe(0);
});

it('refuses a document ANAF\'s validator rejects and records nothing', function () {
    Http::fake(['webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-nok-structured.json'))]);

    expect(fn () => EFactura::submit(Invoices::standard()))->toThrow(DocumentInvalid::class, 'ANAF validation: BR-CO-26, ERRIdentif')
        ->and(Submission::query()->count())->toBe(0);
});

it('throws NotAuthorised when no authorisation covers the seller', function () {
    Http::fake(['webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json'))]);
    Authorisation::query()->delete();

    expect(fn () => EFactura::submit(Invoices::standard()))->toThrow(NotAuthorised::class);
});

it('accepts raw UBL, explicit options and a subject model', function () {
    Queue::fake();
    Http::fake([
        'webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json')),
        'api.anaf.ro/test/FCTEL/rest/uploadb2c*' => Http::response(anafFixture('upload-ok.xml'), 200, ['Content-Type' => 'application/xml']),
    ]);
    $xml = (new UblWriter)->write(Invoices::standard('PV-2026-000777'));
    $subject = Authorised::for('40000000');

    $submission = app(Submitter::class)->submit($xml, new UploadOptions(b2c: true, foreignBuyer: true), $subject);

    expect($submission->document_number)->toBe('PV-2026-000777')
        ->and($submission->xml)->toBe($xml)
        ->and($submission->b2c)->toBeTrue()
        ->and($submission->upload_options)->toBe(['extern' => 'DA'])
        ->and($submission->subject)->toBeInstanceOf(Authorisation::class)
        ->and($submission->phase)->toBe(SubmissionPhase::PROCESSING);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/uploadb2c?standard=UBL&cif=12345674&extern=DA'));
});

it('skips the remote validator when configured off', function () {
    Queue::fake();
    config()->set('efactura.submissions.validate_remotely', false);
    Http::fake(['api.anaf.ro/test/FCTEL/rest/upload*' => Http::response(anafFixture('upload-ok.xml'), 200, ['Content-Type' => 'application/xml'])]);

    EFactura::submit(Invoices::standard());

    Http::assertSentCount(1);
});
