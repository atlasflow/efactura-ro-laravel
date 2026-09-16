<?php

declare(strict_types=1);

use AtlasFlow\EFacturaRo\Laravel\Http\LaravelHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;

it('sends a PSR-7 request through the Http facade and returns a PSR-7 response', function () {
    Http::fake(['api.anaf.ro/*' => Http::response('<header stare="ok"/>', 200, ['Content-Type' => 'application/xml'])]);

    $client = app(ClientInterface::class);
    $request = new Request('POST', 'https://api.anaf.ro/test/FCTEL/rest/upload?standard=UBL&cif=12345674', ['Authorization' => 'Bearer tok', 'Content-Type' => 'application/xml'], '<Invoice/>');

    $response = $client->sendRequest($request);

    expect($client)->toBeInstanceOf(LaravelHttpClient::class)
        ->and($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('<header stare="ok"/>')
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/xml');

    Http::assertSent(fn ($sent) => $sent->url() === 'https://api.anaf.ro/test/FCTEL/rest/upload?standard=UBL&cif=12345674'
        && $sent->hasHeader('Authorization', 'Bearer tok')
        && $sent->hasHeader('Content-Type', 'application/xml')
        && $sent->body() === '<Invoice/>');
});

it('passes non-2xx statuses through instead of throwing', function () {
    Http::fake(['api.anaf.ro/*' => Http::response('{"message":"Access Denied"}', 403)]);

    $response = app(ClientInterface::class)->sendRequest(new Request('GET', 'https://api.anaf.ro/test/FCTEL/rest/stareMesaj?id_incarcare=1'));

    expect($response->getStatusCode())->toBe(403);
});

it('turns a connection failure into a PSR-18 network exception', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));

    try {
        app(ClientInterface::class)->sendRequest(new Request('GET', 'https://api.anaf.ro/x'));
        $this->fail('expected a network exception');
    } catch (NetworkExceptionInterface $e) {
        expect($e->getMessage())->toContain('connection refused')
            ->and((string) $e->getRequest()->getUri())->toBe('https://api.anaf.ro/x');
    }
});
