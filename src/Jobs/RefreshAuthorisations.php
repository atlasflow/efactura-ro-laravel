<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Jobs;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Daily: rotate every pair whose access token is near expiry. */
final class RefreshAuthorisations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(AuthorisationManager $authorisations): void
    {
        $authorisations->refreshAll();
    }
}
