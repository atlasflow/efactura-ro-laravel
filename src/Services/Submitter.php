<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Services;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\RateLimited;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\TransportFailure;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\UploadRefused;
use AtlasFlow\EFacturaRo\Anaf\PollSchedule;
use AtlasFlow\EFacturaRo\Anaf\UploadOptions;
use AtlasFlow\EFacturaRo\Anaf\UploadStandard;
use AtlasFlow\EFacturaRo\Document\Document;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionDeferred;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionRefused;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\DocumentInvalid;
use AtlasFlow\EFacturaRo\Laravel\Jobs\PollSubmission;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Support\Cui;
use AtlasFlow\EFacturaRo\Ubl\UblReader;
use AtlasFlow\EFacturaRo\Ubl\UblWriter;
use AtlasFlow\EFacturaRo\Validation\LocalValidator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Takes a document from "valid" to "ANAF has it". Validation failures throw
 * DocumentInvalid before any row exists; an unavailable ANAF creates the
 * row as PENDING_UPLOAD and lets PollSubmission finish the job, so nothing
 * is marked failed because ANAF was down.
 */
final class Submitter
{
    public function __construct(
        private readonly AnafClient $anaf,
        private readonly AuthorisationManager $authorisations,
        private readonly LocalValidator $local,
        private readonly UblWriter $writer,
        private readonly UblReader $reader,
        private readonly Config $config,
        private readonly Dispatcher $events,
        private readonly Bus $bus,
    ) {}

    /**
     * @param  Document|string  $document  a Document, or CIUS-RO UBL bytes produced elsewhere
     * @param  Cui|null  $cif  the CUI the token holds SPV rights for; defaults to the seller's
     */
    public function submit(Document|string $document, ?UploadOptions $options = null, ?Model $subject = null, ?Cui $cif = null): Submission
    {
        $model = is_string($document) ? $this->reader->read($document) : $document;
        $xml = is_string($document) ? $document : $this->writer->write($document);
        $options ??= UploadOptions::for($model);
        $cif ??= $model->seller->cui() ?? throw new \InvalidArgumentException('The seller has no CUI; pass the CUI the token holds SPV rights for.');

        $local = $this->local->validate($model);

        if (! $local->ok) {
            throw new DocumentInvalid($local, 'local');
        }

        $submission = new Submission;
        $submission->cui = $cif->digits();
        $submission->standard = UploadStandard::for($model->type)->value;
        $submission->document_type = $model->type->value;
        $submission->document_number = $model->number;
        $submission->xml = $xml;
        $submission->b2c = $options->b2c;
        $submission->upload_options = $options->query();
        $submission->phase = SubmissionPhase::PENDING_UPLOAD;
        $submission->attempts = 0;

        if ($subject !== null) {
            $submission->subject()->associate($subject);
        }

        if ($this->config->get('efactura.submissions.validate_remotely', true)) {
            try {
                $remote = $this->anaf->validate($xml);

                if (! $remote->ok) {
                    throw new DocumentInvalid($remote, 'ANAF');
                }
            } catch (TransportFailure|RateLimited $e) {
                $submission->save();

                return $this->defer($submission, $e->getMessage());
            }
        }

        $submission->save();

        return $this->upload($submission);
    }

    /** Upload a PENDING_UPLOAD submission now; used by submit() and again by PollSubmission after a deferral. */
    public function upload(Submission $submission): Submission
    {
        $cui = Cui::of($submission->cui);
        $token = $this->authorisations->tokenFor($cui);
        $options = $this->optionsOf($submission);

        try {
            $receipt = $this->anaf->upload($submission->xml, UploadStandard::from($submission->standard), $cui, $options, $token);
        } catch (UploadRefused $e) {
            $submission->phase = SubmissionPhase::REFUSED;
            $submission->last_error = $e->messages;
            $submission->resolved_at = CarbonImmutable::now();
            $submission->next_poll_at = null;
            $submission->save();

            $this->events->dispatch(new SubmissionRefused($submission, $e->messages));

            return $submission;
        } catch (TransportFailure|RateLimited $e) {
            return $this->defer($submission, $e->getMessage());
        }

        $submission->phase = SubmissionPhase::PROCESSING;
        $submission->upload_index = $receipt->index;
        $submission->submitted_at = CarbonImmutable::instance($receipt->receivedAt);
        $submission->attempts = 0;
        $submission->next_poll_at = CarbonImmutable::now()->addSeconds(PollSchedule::nextDelay(1));
        $submission->last_error = null;
        $submission->save();

        $this->schedule($submission, PollSchedule::nextDelay(1));

        return $submission;
    }

    /** ANAF was unavailable: keep the row live, set the next attempt, tell the consumer the clock is still running. */
    public function defer(Submission $submission, string $reason): Submission
    {
        $seconds = (int) $this->config->get('efactura.submissions.defer_seconds', 300);

        $submission->next_poll_at = CarbonImmutable::now()->addSeconds($seconds);
        $submission->last_error = ['deferred' => $reason];
        $submission->save();

        $this->events->dispatch(new SubmissionDeferred($submission, $reason));
        $this->schedule($submission, $seconds);

        return $submission;
    }

    public function schedule(Submission $submission, int $delaySeconds): void
    {
        $job = (new PollSubmission($submission->id))->delay($delaySeconds);

        if (($connection = $this->config->get('efactura.queue.connection')) !== null) {
            $job->onConnection((string) $connection);
        }

        $job->onQueue((string) $this->config->get('efactura.queue.queue', 'default'));

        $this->bus->dispatch($job);
    }

    private function optionsOf(Submission $submission): UploadOptions
    {
        $query = $submission->upload_options ?? [];

        return new UploadOptions(
            b2c: $submission->b2c,
            foreignBuyer: isset($query['extern']),
            selfBilled: isset($query['autofactura']),
            enforcement: isset($query['executare']),
        );
    }
}
