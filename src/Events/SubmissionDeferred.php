<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Submission;

/** ANAF was unavailable; the submission stays live and will be retried at `next_poll_at`. The legal clock is still running. */
final class SubmissionDeferred
{
    public function __construct(
        public readonly Submission $submission,
        public readonly string $reason,
    ) {}
}
