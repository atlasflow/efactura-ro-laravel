<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Services;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\Message;
use AtlasFlow\EFacturaRo\Anaf\MessageType;
use AtlasFlow\EFacturaRo\Anaf\Quotas;
use AtlasFlow\EFacturaRo\Laravel\Contracts\BundleStore;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\BuyerMessageReceived;
use AtlasFlow\EFacturaRo\Laravel\Events\InvoiceReceived;
use AtlasFlow\EFacturaRo\Laravel\Jobs\PollSubmission;
use AtlasFlow\EFacturaRo\Laravel\Models\InboxMessage;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Support\Cui;
use AtlasFlow\EFacturaRo\Ubl\UblReader;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The persisted mailbox: sweeps ANAF's paginated message list for a CUI,
 * stores each row once (unique on anaf_id), downloads and parses received
 * invoices and buyer messages, and nudges any of our own submissions the
 * list says have resolved. The limiter key is per CUI — MF's quota is.
 */
final class Inbox
{
    public function __construct(
        private readonly AnafClient $anaf,
        private readonly AuthorisationManager $authorisations,
        private readonly BundleStore $bundles,
        private readonly UblReader $reader,
        private readonly Config $config,
        private readonly Dispatcher $events,
        private readonly Bus $bus,
    ) {}

    /**
     * @return int the number of new rows stored
     */
    public function sync(Cui $cui, ?DateTimeImmutable $since = null): int
    {
        $token = $this->authorisations->tokenFor($cui);
        $now = CarbonImmutable::now();
        $days = min(Quotas::LIST_MAX_DAYS, max(1, (int) $this->config->get('efactura.inbox.sync_days', 7)));
        $from = $since === null ? $now->subDays($days) : CarbonImmutable::instance($since)->max($now->subDays(Quotas::LIST_MAX_DAYS));

        $stored = 0;
        $page = 1;

        do {
            RateLimiter::attempt('efactura:list:'.$cui->digits(), Quotas::LIST_PAGINATED_PER_DAY, fn () => true, 86400)
                ?: throw new \RuntimeException(sprintf('The daily list quota for CUI %s is spent.', $cui->digits()));

            $result = $this->anaf->messagesBetween($cui, $from, $now, $page, $token);

            foreach ($result->messages as $message) {
                if ($this->store($cui, $message, $token)) {
                    $stored++;
                }
            }

            $page++;
        } while ($result->hasMore());

        return $stored;
    }

    /** Store one message if new; download and parse what the consumer wants to see. Returns true when the row is new. */
    public function store(Cui $cui, Message $message, string $token): bool
    {
        if (InboxMessage::query()->where('anaf_id', $message->id)->exists()) {
            return false;
        }

        $row = new InboxMessage;
        $row->cui = $cui->digits();
        $row->anaf_id = $message->id;
        $row->type = $message->type;
        $row->request_index = $message->requestIndex;
        $row->seller_cui = $message->sellerCui;
        $row->buyer_cui = $message->buyerCui;
        $row->details = $message->details;
        $row->anaf_created_at = CarbonImmutable::instance($message->createdAt);
        $row->save();

        match ($message->type) {
            MessageType::INVOICE_RECEIVED => $this->receiveInvoice($row, $token),
            MessageType::BUYER_MESSAGE_RECEIVED => $this->receiveBuyerMessage($row, $token),
            MessageType::INVOICE_SENT, MessageType::INVOICE_ERRORS => $this->reconcile($row),
            default => null,
        };

        return true;
    }

    private function receiveInvoice(InboxMessage $row, string $token): void
    {
        $bundle = $this->anaf->download($row->anaf_id, $token);
        $row->bundle_path = $this->bundles->store($bundle, sprintf('%s/inbox/%s', $row->cui, $row->anaf_id));

        $document = $this->reader->read($bundle->payloadXml);
        $row->document_number = $document->number;
        $row->document_date = CarbonImmutable::instance($document->issueDate);
        $row->document_currency = $document->currency;
        $row->document_total = $document->totals->payableAmount->rounded(2)->toString();
        $row->parsed_at = CarbonImmutable::now();
        $row->save();

        $this->events->dispatch(new InvoiceReceived($row, $document, $bundle));
    }

    private function receiveBuyerMessage(InboxMessage $row, string $token): void
    {
        $bundle = $this->anaf->download($row->anaf_id, $token);
        $row->bundle_path = $this->bundles->store($bundle, sprintf('%s/inbox/%s', $row->cui, $row->anaf_id));
        $row->parsed_at = CarbonImmutable::now();
        $row->save();

        $this->events->dispatch(new BuyerMessageReceived($row, $bundle));
    }

    /** The list says one of our uploads has resolved: if we are still polling it, poll now. */
    private function reconcile(InboxMessage $row): void
    {
        if ($row->request_index === null) {
            return;
        }

        $submission = Submission::query()->where('upload_index', (int) $row->request_index)->where('phase', SubmissionPhase::PROCESSING->value)->first();

        if ($submission !== null) {
            $submission->download_id = $submission->download_id ?? $row->anaf_id;
            $submission->next_poll_at = CarbonImmutable::now();
            $submission->save();

            $this->bus->dispatch(new PollSubmission($submission->id));
        }
    }
}
