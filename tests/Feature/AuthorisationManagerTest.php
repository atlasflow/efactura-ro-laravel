<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState;
use AtlasFlow\EFacturaRo\Laravel\Events\AuthorisationExpired;
use AtlasFlow\EFacturaRo\Laravel\Events\AuthorisationExpiring;
use AtlasFlow\EFacturaRo\Laravel\Exceptions\NotAuthorised;
use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Authorised;
use AtlasFlow\EFacturaRo\Support\Cui;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function jwtWith(array $claims): string
{
    $encode = fn (array $a) => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS512']).'.'.$encode($claims).'.sig';
}

function tokenResponse(string $serial = 'CERT-9'): array
{
    $now = CarbonImmutable::now()->getTimestamp();

    return [
        'access_token' => jwtWith(['iat' => $now, 'exp' => $now + 90 * 86400, 'serial' => $serial]),
        'refresh_token' => jwtWith(['iat' => $now, 'exp' => $now + 365 * 86400]),
        'token_type' => 'bearer',
        'expires_in' => 7775999,
    ];
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-09-16 10:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('builds the start URL from config', function () {
    $url = app(AuthorisationManager::class)->startUrl('nonce-1');

    expect($url)->toStartWith('https://logincert.anaf.ro/anaf-oauth2/v1/authorize?')
        ->and($url)->toContain('client_id=app-id')
        ->and($url)->toContain('redirect_uri=https%3A%2F%2Fapp.example%2Fefactura%2Fcallback')
        ->and($url)->toEndWith('state=nonce-1');
});

it('completes the ceremony, stores the pair encrypted and hides it from serialisation', function () {
    Http::fake(['logincert.anaf.ro/*' => Http::response(tokenResponse())]);

    $authorisation = app(AuthorisationManager::class)->complete('the-code', Cui::of('RO12345674'), 'Ana Pop');

    $raw = DB::table('efactura_authorisations')->where('id', $authorisation->id)->first();

    expect($authorisation->started_for_cui)->toBe('12345674')
        ->and($authorisation->certificate_serial)->toBe('CERT-9')
        ->and($authorisation->label)->toBe('Ana Pop')
        ->and($authorisation->access_expires_at->toDateString())->toBe('2026-12-15')
        ->and($authorisation->refresh_expires_at->toDateString())->toBe('2027-09-16')
        ->and($raw->access_token)->not->toBe($authorisation->access_token)
        ->and(decrypt($raw->access_token, false))->toBe($authorisation->access_token)
        ->and($authorisation->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
        ->and(app(AuthorisationManager::class)->stateFor(Cui::of('12345674')))->toBe(AuthorisationState::ACTIVE);

    Http::assertSent(fn ($request) => $request->url() === 'https://logincert.anaf.ro/anaf-oauth2/v1/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('app-id:app-secret'))
        && str_contains($request->body(), 'grant_type=authorization_code&code=the-code'));
});

it('reports NONE, ACTIVE, EXPIRING and EXPIRED', function () {
    $manager = app(AuthorisationManager::class);

    expect($manager->stateFor(Cui::of('12345674')))->toBe(AuthorisationState::NONE);

    Authorised::for('12345674', refreshExpiresAt: CarbonImmutable::now()->addDays(20));
    expect($manager->stateFor(Cui::of('12345674')))->toBe(AuthorisationState::EXPIRING);

    Authorised::for('40000000', refreshExpiresAt: CarbonImmutable::now()->subDay());
    expect($manager->stateFor(Cui::of('40000000')))->toBe(AuthorisationState::EXPIRED);

    Authorised::for('11223342');
    expect($manager->stateFor(Cui::of('11223342')))->toBe(AuthorisationState::ACTIVE)
        ->and(AuthorisationState::EXPIRING->isUsable())->toBeTrue()
        ->and(AuthorisationState::EXPIRED->isUsable())->toBeFalse();
});

it('hands out the stored token while it is fresh and refuses a CUI the authorisation does not cover', function () {
    Authorised::for('12345674');
    $manager = app(AuthorisationManager::class);

    expect($manager->tokenFor(Cui::of('12345674')))->toBe('access-12345674')
        ->and(fn () => $manager->tokenFor(Cui::of('40000000')))->toThrow(NotAuthorised::class, 'state: none');

    Http::assertNothingSent();
});

it('refreshes a pair that expires within the window, rotating both tokens, only once', function () {
    Http::fake(['logincert.anaf.ro/*' => Http::response(tokenResponse('CERT-NEW'))]);
    $authorisation = Authorised::for('12345674', accessExpiresAt: CarbonImmutable::now()->addDays(3));

    $manager = app(AuthorisationManager::class);
    $token = $manager->tokenFor(Cui::of('12345674'));
    $manager->tokenFor(Cui::of('12345674'));

    $authorisation->refresh();

    expect($token)->toStartWith('eyJ')
        ->and($authorisation->access_token)->toBe($token)
        ->and($authorisation->refresh_token)->not->toBe('refresh-12345674')
        ->and($authorisation->certificate_serial)->toBe('CERT-NEW')
        ->and($authorisation->refreshed_at?->toDateTimeString())->toBe('2026-09-16 10:00:00')
        ->and($authorisation->access_expires_at->toDateString())->toBe('2026-12-15');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->body(), 'grant_type=refresh_token&refresh_token=refresh-12345674'));
});

it('raises AuthorisationExpired and throws when ANAF refuses to rotate', function () {
    Event::fake([AuthorisationExpired::class]);
    Http::fake(['logincert.anaf.ro/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token revoked'], 400)]);
    Authorised::for('12345674', accessExpiresAt: CarbonImmutable::now()->addDay());

    expect(fn () => app(AuthorisationManager::class)->tokenFor(Cui::of('12345674')))->toThrow(NotAuthorised::class, 'state: expired');

    Event::assertDispatched(AuthorisationExpired::class, fn ($e) => $e->reason !== null && str_contains($e->reason, 'Refresh token revoked'));
});

it('throws and raises AuthorisationExpired for a dead refresh token without calling ANAF', function () {
    Event::fake([AuthorisationExpired::class]);
    Authorised::for('12345674', refreshExpiresAt: CarbonImmutable::now()->subMinute());

    expect(fn () => app(AuthorisationManager::class)->tokenFor(Cui::of('12345674')))->toThrow(NotAuthorised::class);

    Event::assertDispatched(AuthorisationExpired::class);
    Http::assertNothingSent();
});

it('refreshAll rotates only what is due and warnExpiring flags the dying ones', function () {
    Event::fake([AuthorisationExpiring::class]);
    Http::fake(['logincert.anaf.ro/*' => Http::response(tokenResponse())]);

    Authorised::for('12345674', accessExpiresAt: CarbonImmutable::now()->addDays(2));
    Authorised::for('40000000');
    Authorised::for('11223342', refreshExpiresAt: CarbonImmutable::now()->addDays(10));

    $manager = app(AuthorisationManager::class);

    expect($manager->refreshAll())->toBe(1)
        ->and($manager->warnExpiring())->toBe(1);

    Http::assertSentCount(1);
    Event::assertDispatched(AuthorisationExpiring::class, fn ($e) => $e->authorisation->started_for_cui === '11223342');
});

it('prefers the authorisation that lives longest when several cover a CUI', function () {
    $older = Authorised::for('12345674', refreshExpiresAt: CarbonImmutable::now()->addDays(100));
    $newer = Authorised::for('12345674', refreshExpiresAt: CarbonImmutable::now()->addDays(300));

    expect(app(AuthorisationManager::class)->for(Cui::of('12345674'))?->id)->toBe($newer->id)
        ->and(Authorisation::covering(Cui::of('12345674'))->count())->toBe(2)
        ->and($older->covers(Cui::of('40000000')))->toBeFalse();
});
