<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Submission;

/** Polling gave up after the configured attempts; the state at ANAF is unknown. */
final class SubmissionAbandoned
{
    public function __construct(public readonly Submission $submission) {}
}
