<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Models;

use AtlasFlow\EFacturaRo\Anaf\MessageType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of ANAF's message list, kept so a sync is idempotent (unique on
 * `anaf_id`) and so received invoices can be listed before their bundle
 * is opened.
 *
 * @property int $id
 * @property string $cui
 * @property string $anaf_id
 * @property MessageType $type
 * @property string|null $request_index
 * @property string|null $seller_cui
 * @property string|null $buyer_cui
 * @property string $details
 * @property CarbonImmutable $anaf_created_at
 * @property string|null $bundle_path
 * @property CarbonImmutable|null $parsed_at
 * @property string|null $document_number
 * @property CarbonImmutable|null $document_date
 * @property string|null $document_currency
 * @property string|null $document_total
 */
final class InboxMessage extends Model
{
    protected $table = 'efactura_inbox_messages';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'anaf_created_at' => 'immutable_datetime',
            'parsed_at' => 'immutable_datetime',
            'document_date' => 'immutable_date',
            'document_total' => 'decimal:2',
        ];
    }
}
