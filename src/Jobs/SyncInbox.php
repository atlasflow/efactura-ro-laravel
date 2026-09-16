<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Jobs;

use AtlasFlow\EFacturaRo\Anaf\Exceptions\RateLimited;
use AtlasFlow\EFacturaRo\Anaf\Exceptions\TransportFailure;
use AtlasFlow\EFacturaRo\Laravel\Services\Inbox;
use AtlasFlow\EFacturaRo\Support\Cui;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sweep the mailbox for one CUI; an ANAF outage re-schedules the sweep rather than failing it. */
final class SyncInbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [300, 900, 1800, 3600];

    public function __construct(
        public readonly string $cui,
        public readonly ?DateTimeImmutable $since = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->cui;
    }

    public function handle(Inbox $inbox): void
    {
        try {
            $inbox->sync(Cui::of($this->cui), $this->since);
        } catch (TransportFailure|RateLimited) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
