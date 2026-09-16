<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Console;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use Illuminate\Console\Command;

final class RefreshTokensCommand extends Command
{
    protected $signature = 'efactura:refresh-tokens';

    protected $description = 'Rotate every ANAF token pair that is near expiry and flag the ones whose refresh token is dying';

    public function handle(AuthorisationManager $authorisations): int
    {
        $refreshed = $authorisations->refreshAll();
        $flagged = $authorisations->warnExpiring();

        $this->info(sprintf('%d refreshed, %d expiring soon.', $refreshed, $flagged));

        return self::SUCCESS;
    }
}
