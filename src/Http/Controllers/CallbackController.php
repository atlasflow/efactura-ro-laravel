<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Http\Controllers;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Support\Cui;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET {prefix}/callback?code=…[&state=…] — complete the ceremony for the
 * CUI remembered at /authorise. `state` is checked when ANAF sends it back
 * and not required, because whether it round-trips is unconfirmed.
 */
final class CallbackController
{
    public function __invoke(Request $request, AuthorisationManager $authorisations): JsonResponse
    {
        $pending = $request->session()->pull('efactura.authorising');

        if (! is_array($pending) || ! isset($pending['cui'])) {
            throw new HttpException(400, 'No authorisation was started in this session.');
        }

        if ($request->filled('state') && $request->query('state') !== $pending['nonce']) {
            throw new HttpException(400, 'The state does not match the authorisation that was started.');
        }

        if (! $request->filled('code')) {
            throw new HttpException(400, 'ANAF sent no authorisation code: '.(string) $request->query('error_description', $request->query('error', 'unknown')));
        }

        $authorisation = $authorisations->complete((string) $request->query('code'), Cui::of($pending['cui']), $pending['label'] ?? null);

        return new JsonResponse([
            'cui' => $authorisation->started_for_cui,
            'state' => $authorisations->stateOf($authorisation)->value,
            'access_expires_at' => $authorisation->access_expires_at->toIso8601String(),
            'refresh_expires_at' => $authorisation->refresh_expires_at->toIso8601String(),
        ]);
    }
}
