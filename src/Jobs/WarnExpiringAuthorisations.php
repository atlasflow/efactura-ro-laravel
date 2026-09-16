<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Jobs;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Daily: raise AuthorisationExpiring for every pair inside the warning window. */
final class WarnExpiringAuthorisations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(AuthorisationManager $authorisations): void
    {
        $authorisations->warnExpiring();
    }
}
