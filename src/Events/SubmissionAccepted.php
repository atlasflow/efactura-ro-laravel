<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Anaf\SignedBundle;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;

/** ANAF said ok and the signed bundle is stored: the invoice is in the buyer's SPV. */
final class SubmissionAccepted
{
    public function __construct(
        public readonly Submission $submission,
        public readonly SignedBundle $bundle,
    ) {}
}
