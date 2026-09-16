<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Jobs;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\ErrorsBundle;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\RateLimited;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\TransportFailure;
use AtlasFlow\EFacturaRo\Laravel\Contracts\BundleStore;
use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionAccepted;
use AtlasFlow\EFacturaRo\Laravel\Events\SubmissionRejected;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Support\Cui;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetches the signed bundle of a resolved submission, stores it, and only
 * then raises SubmissionAccepted or SubmissionRejected — the consumer sees
 * the event together with the proof (or the error list) it needs.
 */
final class DownloadBundle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $submissionId) {}

    public function handle(AnafClient $anaf, AuthorisationManager $authorisations, BundleStore $bundles, Dispatcher $events): void
    {
        $submission = Submission::query()->find($this->submissionId);

        if ($submission === null || $submission->download_id === null || $submission->bundle_path !== null) {
            return;
        }

        try {
            $token = $authorisations->tokenFor(Cui::of($submission->cui));
            $bundle = $anaf->download($submission->download_id, $token);
        } catch (TransportFailure|RateLimited $e) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $submission->bundle_path = $bundles->store($bundle, sprintf('%s/submissions/%s', $submission->cui, $submission->upload_index));

        if ($submission->phase === SubmissionPhase::REJECTED) {
            $submission->last_error = ErrorsBundle::messages($bundle->payloadXml);
            $submission->save();
            $events->dispatch(new SubmissionRejected($submission, $submission->last_error));

            return;
        }

        $submission->save();
        $events->dispatch(new SubmissionAccepted($submission, $bundle));
    }
}
