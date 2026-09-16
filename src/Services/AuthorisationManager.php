<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Services;

use AtlasFlow\EFacturaRo\Anaf\Exceptions\Unauthorised;
use AtlasFlow\EFacturaRo\Anaf\OAuth\AuthorizationUrl;
use AtlasFlow\EFacturaRo\Anaf\OAuth\OAuthClient;
use AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState;
use AtlasFlow\EFacturaRo\Laravel\Events\AuthorisationExpired;
use AtlasFlow\EFacturaRo\Laravel\Events\AuthorisationExpiring;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\NotAuthorised;
use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use AtlasFlow\EFacturaRo\Support\Cui;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Owns the authorisation rows: starts the ceremony, completes it, hands out
 * a usable access token for a CUI, and keeps pairs fresh. A refresh runs
 * under a per-authorisation cache lock so two workers never race two
 * refreshes and never use a pair the other has just rotated.
 */
final class AuthorisationManager
{
    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly Dispatcher $events,
    ) {}

    /** The URL to send the certificate holder to. */
    public function startUrl(?string $state = null): string
    {
        return AuthorizationUrl::build(
            (string) $this->config->get('efactura.client_id'),
            (string) $this->config->get('efactura.redirect_uri'),
            $state,
        );
    }

    /** Exchange the code ANAF sent back (within its 60-second window) and persist the pair for the CUI the ceremony was started for. */
    public function complete(string $code, Cui $startedFor, ?string $label = null, ?string $redirectUri = null): Authorisation
    {
        $pair = $this->oauth->exchange($code, $redirectUri ?? (string) $this->config->get('efactura.redirect_uri'));

        $authorisation = new Authorisation;
        $authorisation->started_for_cui = $startedFor->digits();
        $authorisation->label = $label;
        $authorisation->authorised_at = CarbonImmutable::instance($pair->issuedAt);
        $authorisation->covered_cuis = [];
        $authorisation->applyPair($pair);
        $authorisation->save();

        return $authorisation;
    }

    /** The authorisation that covers the CUI, preferring the one that lives longest. */
    public function for(Cui $cui): ?Authorisation
    {
        return Authorisation::covering($cui)->orderByDesc('refresh_expires_at')->first();
    }

    public function stateFor(Cui $cui): AuthorisationState
    {
        $authorisation = $this->for($cui);

        return $authorisation === null ? AuthorisationState::NONE : $this->stateOf($authorisation);
    }

    public function stateOf(Authorisation $authorisation): AuthorisationState
    {
        $now = CarbonImmutable::now();

        if ($authorisation->refresh_expires_at <= $now) {
            return AuthorisationState::EXPIRED;
        }

        $warnBefore = (int) $this->config->get('efactura.authorisations.warn_before_days', 30);

        return $authorisation->refresh_expires_at <= $now->addDays($warnBefore) ? AuthorisationState::EXPIRING : AuthorisationState::ACTIVE;
    }

    /**
     * A bearer token good for the CUI, refreshed first when it expires
     * within `authorisations.refresh_within_days`.
     *
     * @throws NotAuthorised when no usable authorisation covers the CUI
     */
    public function tokenFor(Cui $cui): string
    {
        $authorisation = $this->for($cui);

        if ($authorisation === null) {
            throw new NotAuthorised($cui, AuthorisationState::NONE);
        }

        $state = $this->stateOf($authorisation);

        if ($state === AuthorisationState::EXPIRED) {
            $this->events->dispatch(new AuthorisationExpired($authorisation));

            throw new NotAuthorised($cui, $state);
        }

        if ($this->needsRefresh($authorisation)) {
            $authorisation = $this->refresh($authorisation);
        }

        return $authorisation->access_token;
    }

    public function needsRefresh(Authorisation $authorisation): bool
    {
        $within = (int) $this->config->get('efactura.authorisations.refresh_within_days', 7);

        return $authorisation->access_expires_at <= CarbonImmutable::now()->addDays($within);
    }

    /** Rotate the pair under a lock; a worker that finds it already rotated uses the fresh row instead. */
    public function refresh(Authorisation $authorisation): Authorisation
    {
        $lock = $this->cache instanceof LockProvider || method_exists($this->cache, 'lock')
            ? $this->cache->lock('efactura:authorisation:'.$authorisation->id, 30)
            : null;

        $run = function () use ($authorisation): Authorisation {
            $fresh = $authorisation->fresh() ?? $authorisation;

            if (! $this->needsRefresh($fresh)) {
                return $fresh;
            }

            try {
                $pair = $this->oauth->refresh($fresh->tokenPair());
            } catch (Unauthorised $e) {
                $this->events->dispatch(new AuthorisationExpired($fresh, $e->getMessage()));

                throw new NotAuthorised(Cui::of($fresh->started_for_cui), AuthorisationState::EXPIRED);
            }

            $fresh->applyPair($pair);
            $fresh->refreshed_at = CarbonImmutable::now();
            $fresh->save();

            return $fresh;
        };

        return $lock === null ? $run() : $lock->block(15, $run);
    }

    /** Refresh every pair that is near expiry — for the scheduled job. Returns the number refreshed. */
    public function refreshAll(): int
    {
        $refreshed = 0;

        foreach (Authorisation::query()->cursor() as $authorisation) {
            if ($this->stateOf($authorisation) === AuthorisationState::EXPIRED || ! $this->needsRefresh($authorisation)) {
                continue;
            }

            try {
                $this->refresh($authorisation);
                $refreshed++;
            } catch (NotAuthorised) {
                // The AuthorisationExpired event has been raised; nothing more to do here.
            }
        }

        return $refreshed;
    }

    /** Raise AuthorisationExpiring for every pair inside the warning window. Returns how many were flagged. */
    public function warnExpiring(): int
    {
        $flagged = 0;

        foreach (Authorisation::query()->cursor() as $authorisation) {
            if ($this->stateOf($authorisation) === AuthorisationState::EXPIRING) {
                $this->events->dispatch(new AuthorisationExpiring($authorisation));
                $flagged++;
            }
        }

        return $flagged;
    }
}
