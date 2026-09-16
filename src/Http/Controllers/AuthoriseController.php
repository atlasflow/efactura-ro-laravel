<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Http\Controllers;

use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;
use AtlasFlow\EFacturaRo\Support\Cui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** GET {prefix}/authorise?cui=… — remember the CUI and send the certificate holder to ANAF. */
final class AuthoriseController
{
    public function __invoke(Request $request, AuthorisationManager $authorisations): RedirectResponse
    {
        $cui = Cui::of((string) $request->query('cui', ''));
        $nonce = Str::random(32);

        $request->session()->put('efactura.authorising', ['cui' => $cui->digits(), 'nonce' => $nonce, 'label' => $request->query('label')]);

        return new RedirectResponse($authorisations->startUrl($nonce));
    }
}
