<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AtlasFlow\EFacturaRo\Laravel\Models\Submission submit(\AtlasFlow\EFacturaRo\Document\Document|string $document, ?\AtlasFlow\EFacturaRo\Anaf\UploadOptions $options = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?\AtlasFlow\EFacturaRo\Support\Cui $cif = null)
 * @method static \AtlasFlow\EFacturaRo\Validation\ValidationResult validate(\AtlasFlow\EFacturaRo\Document\Document $document)
 * @method static \AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState authorisationState(\AtlasFlow\EFacturaRo\Support\Cui $cui)
 * @method static string authorisationUrl(?string $state = null)
 * @method static int syncInbox(\AtlasFlow\EFacturaRo\Support\Cui $cui, ?\DateTimeImmutable $since = null)
 * @method static \AtlasFlow\EFacturaRo\Anaf\AnafClient anaf()
 *
 * @see \AtlasFlow\EFacturaRo\Laravel\EFactura
 */
final class EFactura extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AtlasFlow\EFacturaRo\Laravel\EFactura::class;
    }
}
