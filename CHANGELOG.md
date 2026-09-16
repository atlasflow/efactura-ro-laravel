# Changelog

## v0.0.1 — 2026-09-17

First cut: `LaravelHttpClient` (PSR-18 over the `Http` facade), the three tables and models, `AuthorisationManager`, `Submitter`, `Inbox`, `DiskBundleStore` behind the `BundleStore` contract, the `PollSubmission` / `DownloadBundle` / `SyncInbox` / `RefreshAuthorisations` / `WarnExpiringAuthorisations` jobs, nine events, four artisan commands, the optional authorise/callback routes and the `EFactura` facade. Tested against ANAF's documented responses on sqlite, PostgreSQL and MySQL. Not yet run against a live ANAF session.
