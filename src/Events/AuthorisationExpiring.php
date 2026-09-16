<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;

/** The refresh token dies within the warning window; the certificate holder must authorise again. */
final class AuthorisationExpiring
{
    public function __construct(public readonly Authorisation $authorisation) {}
}
