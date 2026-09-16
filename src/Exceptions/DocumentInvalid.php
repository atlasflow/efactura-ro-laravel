<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Exceptions;

use AtlasFlow\EFacturaRo\Validation\ValidationResult;
use RuntimeException;

/** The document failed validation before anything was sent; `result` says where. */
final class DocumentInvalid extends RuntimeException
{
    public function __construct(public readonly ValidationResult $result, string $stage)
    {
        parent::__construct(sprintf('The document failed %s validation: %s', $stage, implode(', ', $result->codes())));
    }
}
