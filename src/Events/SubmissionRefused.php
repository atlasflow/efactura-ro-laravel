<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Submission;

/** ANAF refused the upload itself (ExecutionStatus 1); nothing was queued at ANAF. */
final class SubmissionRefused
{
    /**
     * @param  list<string>  $messages
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly array $messages,
    ) {}
}
