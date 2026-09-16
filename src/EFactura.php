<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\UploadOptions;
use AtlasFlow\EFacturaRo\Document\Document;
use AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState;
use AtlasFlow\EFacturaRo\Laravel\Models\Submission;
use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Laravel\Services\Inbox;
use AtlasFlow\EFacturaRo\Laravel\Services\Submitter;
use AtlasFlow\EFacturaRo\Support\Cui;
use AtlasFlow\EFacturaRo\Validation\ValidationResult;
use AtlasFlow\EFacturaRo\Validation\Validator;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/** The one entry point behind the EFactura facade. */
final class EFactura
{
    public function __construct(
        private readonly Submitter $submitter,
        private readonly AuthorisationManager $authorisations,
        private readonly Inbox $inbox,
        private readonly Validator $validator,
        private readonly AnafClient $anaf,
    ) {}

    public function submit(Document|string $document, ?UploadOptions $options = null, ?Model $subject = null, ?Cui $cif = null): Submission
    {
        return $this->submitter->submit($document, $options, $subject, $cif);
    }

    /** Local rules, then ANAF's public validator. */
    public function validate(Document $document): ValidationResult
    {
        return $this->validator->full($document);
    }

    public function authorisationState(Cui $cui): AuthorisationState
    {
        return $this->authorisations->stateFor($cui);
    }

    public function authorisationUrl(?string $state = null): string
    {
        return $this->authorisations->startUrl($state);
    }

    public function syncInbox(Cui $cui, ?DateTimeImmutable $since = null): int
    {
        return $this->inbox->sync($cui, $since);
    }

    public function anaf(): AnafClient
    {
        return $this->anaf;
    }
}
