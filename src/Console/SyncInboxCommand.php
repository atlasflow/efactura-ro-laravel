<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Console;

use AtlasFlow\EFacturaRo\Laravel\Jobs\SyncInbox;
use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use AtlasFlow\EFacturaRo\Support\Cui;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;

final class SyncInboxCommand extends Command
{
    protected $signature = 'efactura:sync-inbox {cui? : One CUI; every authorised CUI when omitted} {--sync : Run inline instead of queueing}';

    protected $description = 'Sweep the ANAF message list into the inbox';

    public function handle(Bus $bus): int
    {
        $cuis = $this->argument('cui') !== null
            ? [Cui::of((string) $this->argument('cui'))->digits()]
            : Authorisation::query()->pluck('started_for_cui')->unique()->values()->all();

        foreach ($cuis as $cui) {
            $job = new SyncInbox($cui);
            $this->option('sync') ? $bus->dispatchSync($job) : $bus->dispatch($job);
            $this->line('Inbox sync '.($this->option('sync') ? 'ran' : 'queued').' for CUI '.$cui.'.');
        }

        return self::SUCCESS;
    }
}
