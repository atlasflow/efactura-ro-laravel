<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Storage;

use AtlasFlow\EFacturaRo\Anaf\BundleKind;
use AtlasFlow\EFacturaRo\Anaf\SignedBundle;
use AtlasFlow\EFacturaRo\Laravel\Contracts\BundleStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/** `{prefix}/{key}/payload.xml`, `signature.xml` and a `kind` marker on one disk. */
final class DiskBundleStore implements BundleStore
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = 'efactura',
    ) {}

    public function store(SignedBundle $bundle, string $key): string
    {
        $path = trim($this->prefix, '/').'/'.trim($key, '/');

        $this->disk->put($path.'/payload.xml', $bundle->payloadXml);
        $this->disk->put($path.'/signature.xml', $bundle->signatureXml);
        $this->disk->put($path.'/kind', $bundle->kind->value."\n".$bundle->payloadFilename."\n".$bundle->signatureFilename);

        return $path;
    }

    public function retrieve(string $path): SignedBundle
    {
        if (! $this->exists($path)) {
            throw new RuntimeException(sprintf('No bundle at %s.', $path));
        }

        [$kind, $payloadName, $signatureName] = array_pad(explode("\n", (string) $this->disk->get($path.'/kind')), 3, '');

        return new SignedBundle(
            BundleKind::from($kind ?: BundleKind::INVOICE->value),
            (string) $this->disk->get($path.'/payload.xml'),
            (string) $this->disk->get($path.'/signature.xml'),
            $payloadName ?: 'payload.xml',
            $signatureName ?: 'signature.xml',
        );
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path.'/payload.xml') && $this->disk->exists($path.'/signature.xml');
    }
}
