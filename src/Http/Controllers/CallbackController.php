<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Http\Controllers;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Support\Cui;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET {prefix}/callback?code=…&state=… — complete the ceremony for the CUI
 * remembered at /authorise.
 *
 * The `state` nonce is what ties ANAF's answer to the session that started
 * the ceremony; without it a crafted callback link could bind an attacker's
 * ANAF authorisation to a victim's CUI. It is therefore required by default.
 * Whether ANAF round-trips `state` is not yet confirmed; an operator who
 * has established that it does not can set `efactura.routes.require_state`
 * to false, accepting that the session check alone then guards the callback.
 */
final class CallbackController
{
    public function __invoke(Request $request, AuthorisationManager $authorisations, Repository $config): JsonResponse
    {
        $pending = $request->session()->pull('efactura.authorising');

        if (! is_array($pending) || ! isset($pending['cui'])) {
            throw new HttpException(400, 'No authorisation was started in this session.');
        }

        $requireState = (bool) $config->get('efactura.routes.require_state', true);

        if ($requireState && ! $request->filled('state')) {
            throw new HttpException(400, 'ANAF sent no state; the callback cannot be tied to the authorisation that was started.');
        }

        if ($request->filled('state') && ! hash_equals((string) $pending['nonce'], (string) $request->query('state'))) {
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
