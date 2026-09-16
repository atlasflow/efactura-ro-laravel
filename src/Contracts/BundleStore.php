<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Contracts;

use AtlasFlow\EFacturaRo\Anaf\SignedBundle;

/**
 * Where signed bundles live. The default writes to the configured disk;
 * a host that meters storage binds its own implementation.
 */
interface BundleStore
{
    /** Persist both halves of the bundle under `$key` and return the path to keep on the row. */
    public function store(SignedBundle $bundle, string $key): string;

    public function retrieve(string $path): SignedBundle;

    public function exists(string $path): bool;
}
