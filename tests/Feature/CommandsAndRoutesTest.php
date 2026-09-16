<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Laravel\EFacturaServiceProvider;
use AtlasFlow\EFacturaRo\Laravel\Enums\AuthorisationState;
use AtlasFlow\EFacturaRo\Laravel\Models\Authorisation;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Authorised;
use AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures\Invoices;
use AtlasFlow\EFacturaRo\Ubl\UblWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

it('efactura:validate reports local and remote results', function () {
    Http::fake(['webservicesp.anaf.ro/*' => Http::response(anafFixture('validare-ok.json'))]);
    $file = tempnam(sys_get_temp_dir(), 'ubl');
    file_put_contents($file, (new UblWriter)->write(Invoices::standard()));

    $this->artisan('efactura:validate', ['file' => $file, '--remote' => true])
        ->expectsOutputToContain('local: ok')
        ->expectsOutputToContain('ANAF: ok (trace aa0f2c2c')
        ->assertSuccessful();

    file_put_contents($file, (new UblWriter)->write(Invoices::standard(payable: '1.00')));

    $this->artisan('efactura:validate', ['file' => $file])
        ->expectsOutputToContain('BR-CO-16')
        ->assertFailed();

    $this->artisan('efactura:validate', ['file' => '/nope.xml'])->assertFailed();
    unlink($file);
});

it('efactura:refresh-tokens rotates and warns', function () {
    CarbonImmutable::setTestNow('2026-09-16 10:00:00');
    Http::fake(['logincert.anaf.ro/*' => Http::response(anafFixture('token.json'))]);
    Authorised::for('12345674', accessExpiresAt: CarbonImmutable::now()->addDay());
    Authorised::for('40000000', refreshExpiresAt: CarbonImmutable::now()->addDays(5));

    $this->artisan('efactura:refresh-tokens')->expectsOutputToContain('1 refreshed, 1 expiring soon')->assertSuccessful();

    expect(Authorisation::query()->where('started_for_cui', '12345674')->firstOrFail()->access_token)->toBe('ACCESS_JWT');
    CarbonImmutable::setTestNow();
});

it('serves the authorise and callback routes when enabled', function () {
    config()->set('efactura.routes.enabled', true);
    config()->set('efactura.routes.middleware', ['web']);
    app(EFacturaServiceProvider::class, ['app' => app()])->boot();

    $start = $this->get('/efactura/authorise?cui=RO12345674&label=Ana');

    $start->assertRedirect();
    $location = (string) $start->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://logincert.anaf.ro/anaf-oauth2/v1/authorize?')
        ->and($query['client_id'])->toBe('app-id')
        ->and($query['state'])->toHaveLength(32);

    Http::fake(['logincert.anaf.ro/*' => Http::response(anafFixture('token.json'))]);

    $this->get('/efactura/callback?code=abc&state=wrong')->assertStatus(400);

    $start = $this->get('/efactura/authorise?cui=RO12345674&label=Ana');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

    $done = $this->get('/efactura/callback?code=abc&state='.$query['state']);

    $done->assertOk()->assertJsonPath('cui', '12345674')->assertJsonPath('state', AuthorisationState::ACTIVE->value);
    expect(Authorisation::query()->where('label', 'Ana')->exists())->toBeTrue();

    // Without state the callback is refused: the nonce is what binds ANAF's answer to the session that started it.
    $this->get('/efactura/authorise?cui=RO12345674');
    $this->get('/efactura/callback?code=abc')->assertStatus(400);

    // An operator who has confirmed ANAF drops `state` can opt out, knowingly.
    config()->set('efactura.routes.require_state', false);
    $this->get('/efactura/authorise?cui=RO12345674');
    $this->get('/efactura/callback?code=abc')->assertOk();
});

it('keeps the routes off by default', function () {
    $this->get('/efactura/authorise?cui=12345674')->assertNotFound();
});
