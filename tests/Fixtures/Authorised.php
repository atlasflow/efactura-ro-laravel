<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures;

use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use Carbon\CarbonImmutable;

final class Authorised
{
    public static function for(string $cui = Invoices::SELLER_CUI, ?CarbonImmutable $accessExpiresAt = null, ?CarbonImmutable $refreshExpiresAt = null): Authorisation
    {
        return Authorisation::query()->create([
            'started_for_cui' => $cui,
            'certificate_serial' => 'CERT-1',
            'covered_cuis' => [],
            'access_token' => 'access-'.$cui,
            'refresh_token' => 'refresh-'.$cui,
            'access_expires_at' => $accessExpiresAt ?? CarbonImmutable::now()->addDays(60),
            'refresh_expires_at' => $refreshExpiresAt ?? CarbonImmutable::now()->addDays(300),
            'authorised_at' => CarbonImmutable::now()->subDays(30),
            'label' => 'Test holder',
        ]);
    }
}
