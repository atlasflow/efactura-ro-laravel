<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Models;

use AtlasFlow\EFacturaRo\Laravel\Enums\SubmissionPhase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One document on its way through ANAF. `subject` is whatever the host
 * application submitted for (an invoice model, say); `xml` is the exact
 * bytes uploaded, kept so a NOK can be shown against what was sent.
 *
 * @property int $id
 * @property string $cui
 * @property string $standard
 * @property string $document_type
 * @property string $document_number
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string $xml
 * @property bool $b2c
 * @property array<string, string> $upload_options
 * @property int|null $upload_index
 * @property string|null $download_id
 * @property SubmissionPhase $phase
 * @property int $attempts
 * @property CarbonImmutable|null $next_poll_at
 * @property array<int|string, mixed>|null $last_error
 * @property string|null $bundle_path
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $resolved_at
 */
final class Submission extends Model
{
    protected $table = 'efactura_submissions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phase' => SubmissionPhase::class,
            'b2c' => 'boolean',
            'upload_options' => 'array',
            'last_error' => 'array',
            'next_poll_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Submissions still being driven: waiting to upload or waiting on ANAF.
     *
     * @return Builder<static>
     */
    public static function live(): Builder
    {
        return self::query()->whereIn('phase', [SubmissionPhase::PENDING_UPLOAD->value, SubmissionPhase::PROCESSING->value]);
    }

    /**
     * Live submissions whose next step is due.
     *
     * @return Builder<static>
     */
    public static function due(): Builder
    {
        return self::live()->where(fn (Builder $q) => $q->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()));
    }

    public function isLive(): bool
    {
        return $this->phase->isLive();
    }
}
