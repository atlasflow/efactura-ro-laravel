<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "test" talks to api.anaf.ro/test — real tokens, real validation,
    | nothing delivered to a real buyer. "prod" is the live system. The
    | public validator has no test variant and is always production.
    |
    */

    'environment' => env('EFACTURA_ENVIRONMENT', 'test'),

    /*
    |--------------------------------------------------------------------------
    | The application registered at ANAF
    |--------------------------------------------------------------------------
    |
    | Registered once, by the platform, at www.anaf.ro/InregOauth. One
    | callback URL per application; the client secret never leaves config.
    |
    */

    'client_id' => env('EFACTURA_CLIENT_ID'),
    'client_secret' => env('EFACTURA_CLIENT_SECRET'),
    'redirect_uri' => env('EFACTURA_REDIRECT_URI'),

    'http' => [
        'timeout' => (int) env('EFACTURA_HTTP_TIMEOUT', 60),
        'connect_timeout' => (int) env('EFACTURA_HTTP_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where signed bundles are kept
    |--------------------------------------------------------------------------
    |
    | The ZIP ANAF returns — the invoice or error list plus MF's signature —
    | is the legal original. It goes to this disk under `prefix`; bind your
    | own BundleStore to put it somewhere counted.
    |
    */

    'bundles' => [
        'disk' => env('EFACTURA_BUNDLE_DISK', 'local'),
        'prefix' => 'efactura',
    ],

    'authorisations' => [
        // Refresh the access token when it expires within this many days.
        'refresh_within_days' => 7,
        // Raise AuthorisationExpiring this many days before the refresh token dies.
        'warn_before_days' => 30,
    ],

    'submissions' => [
        // Stop polling a submission after this many attempts (≈ 4 days on the default schedule).
        'max_attempts' => 96,
        // Wait this long before retrying an upload or poll when ANAF is unavailable.
        'defer_seconds' => 300,
        // Skip ANAF's public validator before uploading (it is the same check ANAF runs on upload).
        'validate_remotely' => true,
    ],

    'inbox' => [
        // How far back the first sync looks; ANAF allows at most 60.
        'sync_days' => 7,
    ],

    'queue' => [
        'connection' => env('EFACTURA_QUEUE_CONNECTION'),
        'queue' => env('EFACTURA_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes for single-application consumers
    |--------------------------------------------------------------------------
    |
    | GET {prefix}/authorise?cui=… sends the certificate holder to ANAF;
    | GET {prefix}/callback completes the ceremony. A multi-tenant host
    | keeps these off and runs its own relay.
    |
    */

    'routes' => [
        'enabled' => (bool) env('EFACTURA_ROUTES', false),
        'prefix' => 'efactura',
        'middleware' => ['web', 'auth'],
    ],

];
