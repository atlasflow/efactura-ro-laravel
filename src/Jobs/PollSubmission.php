<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Jobs;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\RateLimited;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\TransportFailure;
use AtlasFlow\EFacturaRo\Anaf\PollSchedule;
use AtlasFlow\EFacturaRo\Anaf\Quotas;
use AtlasFlow\EFacturaRo\Anaf\SubmissionState;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionAbandoned;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionRefused;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\NotAuthorised;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Laravel\Services\Submitter;
use AtlasFlow\EFacturaRo\Support\Cui;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drives one live submission one step: a PENDING_UPLOAD row is uploaded, a
 * PROCESSING row is polled once. Every ANAF outage re-schedules rather
 * than fails; the daily stareMesaj quota is honoured per upload index; a
 * terminal answer hands over to DownloadBundle.
 */
final class PollSubmission implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $submissionId) {}

    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }

    public function handle(AnafClient $anaf, AuthorisationManager $authorisations, Submitter $submitter, Config $config, Dispatcher $events, Bus $bus): void
    {
        $submission = Submission::query()->find($this->submissionId);

        if ($submission === null || ! $submission->isLive()) {
            return;
        }

        if ($submission->phase === SubmissionPhase::PENDING_UPLOAD) {
            try {
                $submitter->upload($submission);
            } catch (NotAuthorised $e) {
                $submitter->defer($submission, $e->getMessage());
            }

            return;
        }

        $maxAttempts = (int) $config->get('efactura.submissions.max_attempts', 96);

        if ($submission->attempts >= $maxAttempts) {
            $submission->phase = SubmissionPhase::ABANDONED;
            $submission->next_poll_at = null;
            $submission->resolved_at = CarbonImmutable::now();
            $submission->save();
            $events->dispatch(new SubmissionAbandoned($submission));

            return;
        }

        $quotaKey = 'efactura:stare:'.$submission->upload_index;

        if (! RateLimiter::attempt($quotaKey, PollSchedule::MAX_POLLS_PER_DAY, fn () => true, 86400)) {
            $submitter->defer($submission, sprintf('The daily stareMesaj quota (%d) for upload %d is spent.', Quotas::STATUS_PER_MESSAGE_PER_DAY, $submission->upload_index));

            return;
        }

        try {
            $token = $authorisations->tokenFor(Cui::of($submission->cui));
            $status = $anaf->status((int) $submission->upload_index, $token);
        } catch (TransportFailure|RateLimited|NotAuthorised $e) {
            $submitter->defer($submission, $e->getMessage());

            return;
        }

        $submission->attempts++;

        if ($status->state === SubmissionState::PROCESSING) {
            $delay = PollSchedule::nextDelay($submission->attempts + 1);
            $submission->next_poll_at = CarbonImmutable::now()->addSeconds($delay);
            $submission->save();
            $submitter->schedule($submission, $delay);

            return;
        }

        $submission->download_id = $status->downloadId;
        $submission->next_poll_at = null;

        if ($status->state === SubmissionState::REFUSED) {
            $submission->phase = SubmissionPhase::REFUSED;
            $submission->resolved_at = CarbonImmutable::now();
            $submission->last_error = ['ANAF: XML cu erori nepreluat de sistem'];
            $submission->save();
            $events->dispatch(new SubmissionRefused($submission, $submission->last_error));

            return;
        }

        $submission->phase = $status->state === SubmissionState::OK ? SubmissionPhase::ACCEPTED : SubmissionPhase::REJECTED;
        $submission->resolved_at = CarbonImmutable::now();
        $submission->save();

        $bus->dispatch(new DownloadBundle($submission->id));
    }
}
