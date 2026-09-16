<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Anaf\SignedBundle;
use AtlasFlow\EFacturaRo\Document\Document;
use AtlasFlow\EFacturaRo\Laravel\Models\InboxMessage;

/** A supplier's invoice arrived in the SPV, parsed and stored. */
final class InvoiceReceived
{
    public function __construct(
        public readonly InboxMessage $message,
        public readonly Document $document,
        public readonly SignedBundle $bundle,
    ) {}
}
