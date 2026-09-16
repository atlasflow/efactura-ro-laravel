# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

The Laravel bridge for `atlasflow/efactura-ro`. The kernel is stateless and computes nothing; this package owns every write: authorisations and their encrypted token pairs, submissions and their polling, the inbox. Anything that could be a kernel concern (a document rule, an ANAF response shape) belongs in the kernel, not here.

## Rules that hold

- ANAF being unavailable is never a failure: defer, set `next_poll_at`, raise `SubmissionDeferred`. Only ANAF's own answer moves a submission to a terminal phase.
- An authorisation covers exactly `started_for_cui` until ANAF's JWT claim for covered CUIs is confirmed. No silent fallback to another CUI's token.
- Refreshes run under `Cache::lock('efactura:authorisation:{id}')`; both tokens rotate; persist the pair.
- Rate-limiter keys are what MF's quota is keyed on: `efactura:stare:{index}`, `efactura:list:{cui}`.
- Tokens are `encrypted` casts and `$hidden`; nothing logs them.
- The callback route requires `state` unless `routes.require_state` is off.
- Migrations must pass on sqlite, PostgreSQL and MySQL: `composer test`, `test:pgsql`, `test:mysql`.

## Working here

Feature tests fake ANAF with `Http::fake()` and `Http::preventStrayRequests()`; a job under `Queue::fake()` is run with `app()->call([$job, 'handle'])`, because `Bus::dispatchSync` hands a `ShouldQueue` job to the faked queue. `vendor/bin/pint --parallel` and `vendor/bin/phpstan analyse` (Larastan) must be clean. Develop on `dev/dev-<version>`; merge into `master` when green on all three engines. The repository is public — no tokens, no real CUIs with names.
