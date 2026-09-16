<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Models;

use AtlasFlow\EFacturaRo\Anaf\OAuth\TokenPair;
use AtlasFlow\EFacturaRo\Support\Cui;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One OAuth ceremony by one certificate holder. Tokens are encrypted at
 * rest and hidden from serialisation. `started_for_cui` is the CUI the
 * ceremony was run for; `covered_cuis` is what the JWT claims — empty
 * until ANAF's claim is confirmed, in which case only `started_for_cui`
 * counts.
 *
 * @property int $id
 * @property string $started_for_cui
 * @property string|null $certificate_serial
 * @property list<string> $covered_cuis
 * @property string $access_token
 * @property string $refresh_token
 * @property CarbonImmutable $access_expires_at
 * @property CarbonImmutable $refresh_expires_at
 * @property CarbonImmutable $authorised_at
 * @property CarbonImmutable|null $refreshed_at
 * @property string|null $label
 */
final class Authorisation extends Model
{
    protected $table = 'efactura_authorisations';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'covered_cuis' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'immutable_datetime',
            'refresh_expires_at' => 'immutable_datetime',
            'authorised_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Authorisations that cover the CUI, by the ceremony's own CUI or the JWT's list.
     *
     * @return Builder<static>
     */
    public static function covering(Cui $cui): Builder
    {
        return self::query()->where(function (Builder $q) use ($cui): void {
            $q->where('started_for_cui', $cui->digits())
                ->orWhereJsonContains('covered_cuis', $cui->digits());
        });
    }

    public function covers(Cui $cui): bool
    {
        return $this->started_for_cui === $cui->digits() || in_array($cui->digits(), $this->covered_cuis ?? [], true);
    }

    public function tokenPair(): TokenPair
    {
        return new TokenPair(
            accessToken: $this->access_token,
            refreshToken: $this->refresh_token,
            accessExpiresAt: $this->access_expires_at->toDateTimeImmutable(),
            refreshExpiresAt: $this->refresh_expires_at->toDateTimeImmutable(),
            issuedAt: ($this->refreshed_at ?? $this->authorised_at)->toDateTimeImmutable(),
            certificateSerial: $this->certificate_serial,
            coveredCuis: $this->covered_cuis ?? [],
        );
    }

    public function applyPair(TokenPair $pair): void
    {
        $this->access_token = $pair->accessToken;
        $this->refresh_token = $pair->refreshToken;
        $this->access_expires_at = CarbonImmutable::instance($pair->accessExpiresAt);
        $this->refresh_expires_at = CarbonImmutable::instance($pair->refreshExpiresAt);
        $this->certificate_serial = $pair->certificateSerial ?? $this->certificate_serial;
        $this->covered_cuis = $pair->coveredCuis !== [] ? $pair->coveredCuis : ($this->covered_cuis ?? []);
    }
}
