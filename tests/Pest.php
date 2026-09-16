<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in('Feature');

// A stub that stops matching must fail the test, never reach ANAF.
beforeEach(fn () => Http::preventStrayRequests())->in('Feature');

function anafFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/anaf/'.$name);
}

function zipBundle(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}
