<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Enums;

/** What the application can do for a CUI right now. */
enum AuthorisationState: string
{
    /** No authorisation covers the CUI. */
    case NONE = 'none';

    /** Usable; refreshes happen unattended. */
    case ACTIVE = 'active';

    /** Usable, but the refresh token dies within `authorisations.warn_before_days`: the certificate holder must authorise again. */
    case EXPIRING = 'expiring';

    /** The refresh token has died; nothing works until a new ceremony. */
    case EXPIRED = 'expired';

    public function isUsable(): bool
    {
        return $this === self::ACTIVE || $this === self::EXPIRING;
    }
}
