<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Console;

use AtlasFlow\EFacturaRo\Laravel\Jobs\PollSubmission;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as Bus;

/** The scheduled sweep: dispatch a poll for every live submission whose time has come. Cheap when nothing is pending. */
final class PollCommand extends Command
{
    protected $signature = 'efactura:poll {--sync : Run the polls inline instead of queueing them}';

    protected $description = 'Upload deferred submissions and poll ANAF for the ones in processing';

    public function handle(Bus $bus): int
    {
        $count = 0;

        foreach (Submission::due()->orderBy('next_poll_at')->cursor() as $submission) {
            $job = new PollSubmission($submission->id);
            $this->option('sync') ? $bus->dispatchSync($job) : $bus->dispatch($job);
            $count++;
        }

        $this->info(sprintf('%d submission(s) polled.', $count));

        return self::SUCCESS;
    }
}
