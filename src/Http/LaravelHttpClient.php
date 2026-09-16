<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 over Laravel's HTTP client, so the kernel's AnafClient goes through
 * the Http facade: Http::fake() intercepts it in tests, and whatever the
 * application configured (proxies, logging, retries) applies. PSR-7 in,
 * the Laravel response's PSR-7 out. Nothing here names Guzzle.
 */
final class LaravelHttpClient implements ClientInterface
{
    public function __construct(
        private readonly Factory $http,
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 10,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            if (strtolower($name) !== 'content-type') {
                $headers[$name] = implode(', ', $values);
            }
        }

        $pending = $this->http
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['http_errors' => false]);

        $body = (string) $request->getBody();

        if ($body !== '' || $request->hasHeader('Content-Type')) {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/octet-stream');
        }

        try {
            $response = $pending->send($request->getMethod(), (string) $request->getUri());
        } catch (ConnectionException $e) {
            throw new class($e->getMessage(), $request, $e) extends RuntimeException implements NetworkExceptionInterface
            {
                public function __construct(string $message, private readonly RequestInterface $request, \Throwable $previous)
                {
                    parent::__construct($message, 0, $previous);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }

        return $response->toPsrResponse();
    }
}
