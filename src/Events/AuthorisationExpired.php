<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;

/** The refresh token has died, or ANAF refused to rotate it; nothing works for its CUIs until a new ceremony. */
final class AuthorisationExpired
{
    public function __construct(
        public readonly Authorisation $authorisation,
        public readonly ?string $reason = null,
    ) {}
}
