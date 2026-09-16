<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Exceptions;

use AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState;
use AtlasFlow\EFacturaRo\Support\Cui;
use RuntimeException;

/** No usable authorisation covers the CUI; the certificate holder must run the ceremony. */
final class NotAuthorised extends RuntimeException
{
    public function __construct(public readonly Cui $cui, public readonly AuthorisationState $state)
    {
        parent::__construct(sprintf('No usable ANAF authorisation covers CUI %s (state: %s).', $cui->digits(), $state->value));
    }
}
