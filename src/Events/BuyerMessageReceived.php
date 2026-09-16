<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Anaf\SignedBundle;
use AtlasFlow\EFacturaRo\Laravel\Models\InboxMessage;

/** A buyer sent a RASP message about one of our invoices. */
final class BuyerMessageReceived
{
    public function __construct(
        public readonly InboxMessage $message,
        public readonly SignedBundle $bundle,
    ) {}
}
