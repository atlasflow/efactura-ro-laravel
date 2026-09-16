# efactura-ro-laravel

The Laravel bridge for [`atlasflow/efactura-ro`](https://github.com/atlasflow/efactura-ro), the Romanian e-invoicing kernel. The kernel builds, validates and transports the document; this package owns everything that has to be remembered: ANAF authorisations and their token pairs, submissions and their polling, the inbound mailbox, the jobs that drive them and the events that tell your application what happened.

```php
use AtlasFlow\EFacturaRo\Laravel\Facades\EFactura;

$submission = EFactura::submit($document, subject: $invoice);   // validated locally, at ANAF, uploaded, polling queued
```

**Status: `0.0.x` — not yet run against a live ANAF session.** Every service and job is covered by tests against ANAF's documented responses (`Http::fake()`), on sqlite, PostgreSQL 18 and MySQL 8. The OAuth ceremony and the authenticated endpoints have not been exercised with a real certificate holder; the kernel's `tests/Live/UploadTest.php` is the gate that promotes this package to `0.1.0`.

## Requirements

- PHP 8.4, Laravel 12 or 13
- `atlasflow/efactura-ro` (pulled in), a queue worker, a scheduler

## Install

```bash
composer require atlasflow/efactura-ro-laravel
php artisan vendor:publish --tag=efactura-config
php artisan migrate
```

```dotenv
EFACTURA_ENVIRONMENT=test          # test | prod
EFACTURA_CLIENT_ID=…               # the application registered at www.anaf.ro/InregOauth
EFACTURA_CLIENT_SECRET=…
EFACTURA_REDIRECT_URI=https://app.example/efactura/callback
EFACTURA_BUNDLE_DISK=local         # where signed bundles are kept
```

Three tables are created: `efactura_authorisations` (token pairs, encrypted at rest), `efactura_submissions`, `efactura_inbox_messages`.

## Authorising

A certificate holder with SPV rights for the CUI authorises the application once; the server keeps itself authorised for a year with the refresh token, which needs no certificate.

For a single-application host, turn the built-in routes on:

```php
// config/efactura.php
'routes' => ['enabled' => true, 'prefix' => 'efactura', 'middleware' => ['web', 'auth']],
```

`GET /efactura/authorise?cui=RO12345674&label=Ana` sends the holder to ANAF; `GET /efactura/callback` completes the ceremony and stores the pair. The callback requires ANAF's `state` nonce back (CSRF guard) — see `routes.require_state` if you have established that ANAF drops it.

A multi-tenant host runs its own relay and calls the manager directly:

```php
use AtlasFlow\EFacturaRo\Laravel\Services\AuthorisationManager;

$url = app(AuthorisationManager::class)->startUrl($nonce);
$authorisation = app(AuthorisationManager::class)->complete($code, Cui::of('RO12345674'), 'Ana');

EFactura::authorisationState(Cui::of('12345674'));   // NONE | ACTIVE | EXPIRING | EXPIRED
```

Until ANAF's JWT is confirmed to name the CUIs a holder has rights for, an authorisation covers exactly the CUI it was started for. `tokenFor()` refuses any other, refreshes a pair that expires within seven days under a per-authorisation cache lock, and raises `AuthorisationExpired` when the refresh token has died.

## Submitting

```php
use AtlasFlow\EFacturaRo\Laravel\Exceptions\{DocumentInvalid, NotAuthorised};

try {
    $submission = EFactura::submit($document, subject: $invoice);
} catch (DocumentInvalid $e) {
    $e->result->errors;      // local rules or ANAF's validator said no; nothing was sent, nothing was stored
} catch (NotAuthorised $e) {
    $e->state;               // the certificate holder must run the ceremony
}

$submission->phase;          // PENDING_UPLOAD | PROCESSING | ACCEPTED | REJECTED | REFUSED | ABANDONED
$submission->upload_index;   // ANAF's index_incarcare
$submission->bundle_path;    // the signed ZIP, unpacked, once resolved
$submission->last_error;     // ANAF's messages on REJECTED / REFUSED
```

`submit()` validates locally, then at ANAF's public validator, then uploads (`/uploadb2c` for consumers, `extern=DA` for a foreign buyer — both read off the document) and queues `PollSubmission`. Polling follows the kernel's `PollSchedule` (30 s, 1, 2, 5, 15, 30 min, then hourly) under ANAF's 100-per-message-per-day quota. A terminal answer hands over to `DownloadBundle`, which stores the signed bundle through the `BundleStore` contract before the event fires.

**When ANAF is down, nothing fails.** A 5xx or a network error on validation, upload or poll leaves the row live with `next_poll_at` set and raises `SubmissionDeferred`, so the legal clock stays visible while the retry waits. Only ANAF's own answers move a submission to a terminal phase.

Raw UBL produced elsewhere is accepted too: `EFactura::submit($xml, new UploadOptions(b2c: true))`.

### Events

| Event | When |
|---|---|
| `SubmissionAccepted` | ANAF said `ok`; the bundle is stored; the invoice is in the buyer's SPV |
| `SubmissionRejected` | ANAF said `nok`; `messages` are its words, also in `last_error` |
| `SubmissionRefused` | the upload itself was refused (ExecutionStatus 1) |
| `SubmissionDeferred` | ANAF was unavailable; the row stays live |
| `SubmissionAbandoned` | polling stopped after `submissions.max_attempts`; reconcile from the inbox |
| `InvoiceReceived` | a supplier's invoice arrived, parsed into a kernel `Document` |
| `BuyerMessageReceived` | a buyer sent a RASP message about one of your invoices |
| `AuthorisationExpiring` / `AuthorisationExpired` | the refresh token is dying / has died |

## The inbox

```php
EFactura::syncInbox(Cui::of('12345674'));   // paginated sweep, idempotent on anaf_id
```

Received invoices are downloaded, stored and parsed (`document_number`, `document_date`, `document_total`); `InvoiceReceived` carries the kernel `Document`. Rows that say one of your own uploads has resolved nudge its `PollSubmission`. The limiter key is per CUI, as MF's quota is.

## Scheduling

```php
// routes/console.php
Schedule::command('efactura:poll')->everyMinute();          // cheap when nothing is pending
Schedule::command('efactura:refresh-tokens')->daily();
Schedule::command('efactura:sync-inbox')->daily();          // every authorised CUI
```

`php artisan efactura:validate invoice.xml --remote` checks a file locally and at ANAF.

## Storage

`BundleStore` is a contract. `DiskBundleStore` writes `{prefix}/{cui}/submissions/{index}/payload.xml` + `signature.xml` to the configured disk. Bind your own to put bundles somewhere metered.

```php
$this->app->bind(BundleStore::class, MyBundleStore::class);
```

## Tests

```bash
composer test            # sqlite in memory
composer test:pgsql      # EFACTURA_TEST_PGSQL_* (defaults to a local socket, database efactura_test)
composer test:mysql      # EFACTURA_TEST_MYSQL_*
```

## Licence

MIT. Copyright (c) 2026 AtlasFlow.
