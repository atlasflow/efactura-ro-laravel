<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Console;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Validation\LocalValidator;
use AtlasFlow\EFacturaRo\Validation\ValidationResult;
use Illuminate\Console\Command;

final class ValidateCommand extends Command
{
    protected $signature = 'efactura:validate {file : A CIUS-RO UBL file} {--remote : Also ask ANAF\'s public validator}';

    protected $description = 'Validate a UBL invoice or credit note locally, and at ANAF with --remote';

    public function handle(LocalValidator $local, AnafClient $anaf): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $xml = (string) file_get_contents($path);
        $result = $local->validateXml($xml);
        $this->report('local', $result);

        if ($result->ok && $this->option('remote')) {
            $result = $anaf->validate($xml);
            $this->report('ANAF', $result);
        }

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    private function report(string $stage, ValidationResult $result): void
    {
        if ($result->ok) {
            $this->info(sprintf('%s: ok%s', $stage, $result->traceId === null ? '' : ' (trace '.$result->traceId.')'));

            return;
        }

        $this->error(sprintf('%s: %d error(s)', $stage, count($result->errors)));

        foreach ($result->errors as $error) {
            $this->line(sprintf('  [%s] %s at %s: %s', $error->source->value, $error->code, $error->path, $error->message));
        }
    }
}
