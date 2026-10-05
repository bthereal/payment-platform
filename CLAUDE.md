# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A demo dashboard for merchants on a Stripe Connect platform to see sales,
fees, and payouts without logging into Stripe. Monorepo: `api/` (Symfony 8.1 JSON API,
PHP 8.4) + `web/` (React 19 + Vite + Tailwind SPA), talking over HTTP on
separate origins (real CORS, not a dev proxy). Postgres is the only store.

Full product rationale, the three anomalies in the fixture data and how
they're handled, and known limitations are in `README.md` — read it before
making product/domain decisions, it's not just setup instructions.

## Commands

Everything runs through Docker; there is no local PHP/Node/Postgres install.

```
docker compose up                                    # build, migrate, seed, run both services
docker compose down -v                                # full reset (drops the db volume)
```

- Dashboard: http://localhost:5173
- API: http://localhost:8000/api/dashboard
- OpenAPI spec: http://localhost:8000/api/doc.json (JSON only, no Swagger UI)
- Login: `admin@example.com` / `password` (reseeded every dev boot, never in prod)

**Tests** (api only — no web test suite exists):
```
docker compose up -d db api
docker compose exec api php bin/phpunit                              # all 71 tests
docker compose exec api php bin/phpunit --testsuite=unit             # no db/kernel
docker compose exec api php bin/phpunit --testsuite=application      # real db/HTTP
docker compose exec api php bin/phpunit --filter=testMethodName      # single test
```
`tests/bootstrap.php` creates the test db, runs migrations, and generates a
JWT test keypair automatically and idempotently — no manual setup step ever.

**Code quality (api)**:
```
docker compose exec api composer bin psalm install   # one-time
docker compose exec api composer check                # phpcs (PSR-12, 160-char lines) + Psalm
docker compose exec api composer cs:fix                # auto-fix phpcs violations
```

**Web**:
```
docker compose exec web npm run lint      # oxlint
docker compose exec web npm run build     # tsc -b && vite build
```

**Seeding** — the sample data lives in DoctrineFixturesBundle classes
(`src/DataFixtures/`, dev/test only) as raw Stripe records. Two entry points,
same `LedgerSeeder` underneath:
```
docker compose exec api php bin/console app:seed-ledger [--dry-run]     # upsert, never purges, reports dupes/warnings (dev boot uses this)
docker compose exec api php bin/console doctrine:fixtures:load           # purge + reload
docker compose exec api php bin/console app:seed-ledger --file=path      # ad-hoc JSON export instead (tests/Fixtures/*.json)
```

## Architecture

### Domain model is a ledger, not a mirror of Stripe

`Organisation` → `Customer` → `Payment` (a single CREDIT/DEBIT entry) →
`Settlement` (a payout). Everything Stripe-shaped (`charge`/`refund`/`payout`
JSON) is an *ingestion format*, never the domain model itself:

- **`Payment` rows are immutable once written.** A refund never mutates the
  charge it reverses — it's a new `Payment` row (`DEBIT`, source `REFUND`)
  linked back via `relatedPayment`. This is what makes "refunded after
  already paid out" representable without special-casing.
- **`grossAmount`/`stripeFee`/`applicationFee` are stored as non-negative
  magnitudes**; the reporting layer (`DashboardService`/`PaymentRepository`)
  decides add/subtract by `direction`. `netAmount` keeps Stripe's true signed
  value so `SUM(net_amount)` is directly the balance movement.
- **"Pending" (still to come) is derived from settlement linkage** — a
  `Payment` with no `SettlementPayment` row — never from `available_on`
  being in the future. A date-based rule is provably wrong against the
  fixture (see README, "Data anomalies").
- All money is integer minor units throughout. Never introduce floats for
  amounts.

### Ingestion pipeline (`LedgerSeeder`, `SeedLedgerCommand`, `DataFixtures`)

The sample data is held as **raw provider records**, not pre-built entities:
`ChargeFixtures`/`RefundFixtures`/`DisputeFixtures`/`PayoutFixtures` each
return their records from `records()` (the `LedgerRecordSource` interface,
autoconfigured as a tag) and extend `LedgerRecordFixture`, whose `load()`
dedupes and hands them to `LedgerSeeder`. `OrganisationFixtures` creates the
org + demo login and is referenced by the rest; `getDependencies()` encodes
the ingestion order below. Keep the data in this raw shape — building
entities directly would bypass the gateway, dedupe and reconciliation that
the anomalies exist to exercise.

`LedgerSeeder` is the shared write side (dedupe, org/admin upsert, the
per-type passes, payout reconciliation) so the two entry points can't
drift; `SeedLedgerCommandRealFixtureTest` asserts both land identical totals.
`SeedLedgerCommand` only orchestrates: collect records from every
`LedgerRecordSource` (or `--file`), dedupe (reporting which ids collapsed,
escalating a warning if duplicate ids have *different* content), split by
`object` type, then drive `LedgerSeeder` inside one transaction. Neither
does payment creation itself.

- **`PaymentGatewayInterface`** (`normalizeCharge`/`normalizeRefund`) is the
  provider seam. `StripePaymentGateway` is the only implementation. A second
  provider is a new class implementing this interface — no factory or
  registry exists yet, deliberately (see the interface's docblock for where
  that line was drawn; don't add one preemptively).
- **`PaymentService`** turns normalized DTOs into `Payment` rows. It never
  sees raw provider records, only `NormalizedCharge`/`NormalizedRefund` — it
  has no idea what gateway a record came from. It also owns
  customer-resolution (`findOrCreateCustomer`, synthetic customers keyed by
  order ref).
- Settlement/payout reconciliation lives in `LedgerSeeder::recordPayouts`
  (not `PaymentService`) since it isn't payment creation — it links existing
  `Payment` rows to a `Settlement` via `SettlementPayment`, and warns (not
  fails) on a swept-total mismatch or an already-swept payment. Warnings
  are returned, not printed: the command shows them, fixtures log them.
- **Ingestion order matters and is flush-boundaried**: charges, then
  refunds, then payouts — each pass flushed before the next, because repo
  lookups run real SQL and won't see an earlier pass's unflushed inserts.
  Payouts flush *per-payout* within their loop (not once after), so a later
  payout's "already swept?" check sees an earlier payout's link from the
  same pass. Preserve this structure if you touch `LedgerSeeder`.
- The whole seed runs inside one transaction — `wrapInTransaction` in the
  command, the bundle's `ORMExecutor` for fixtures — so a failure partway
  rolls back everything despite the interim flushes. `LedgerSeeder` never
  opens its own; the caller owns it.

### API surface

Symfony 8.1, pure JSON API, no server-rendered pages, no Swagger UI (JSON
spec only — see `nelmio_api_doc.yaml`/`routes.yaml`, deliberate since there's
no templating engine). Two firewalls in `config/packages/security.yaml`:
`login` (stateless, `json_login` against `/api/login`) and `api` (stateless,
JWT bearer). **Access-control rule order matters** — PUBLIC_ACCESS for
`/api/login` and `/api/doc` must precede the general `/api` rule requiring
`IS_AUTHENTICATED_FULLY`, since only the first match applies.

`DashboardController` is a single endpoint (`GET /api/dashboard`) returning
one payload for the whole screen rather than several endpoints the frontend
would orchestrate. `date_from` defaults to the latest activity date in the
data, not wall-clock "now" — the fixture's sales window is long past, so
"now" would make every rolling window read zero. `DashboardService` is pure
orchestration; every actual aggregate (SUM by direction, the pending
NOT-EXISTS query, the paid-out join) lives as a QueryBuilder method on
`PaymentRepository`/`SettlementPaymentRepository`, not in the service.

### Web

Vite + React 19, Tailwind v4 (via `@tailwindcss/vite`, no separate config
file needed). No routing library, no state management library, no test
suite — `App.tsx` gates between `LoginForm` and `Dashboard` on token
presence. JWT lives in `sessionStorage` (`lib/auth.ts`), deliberately not
persisted longer — a lost tab shouldn't leave a token sitting around, and
re-login is one form submit away. `VITE_API_BASE_URL` is always read from
the environment (`lib/apiClient.ts`, `lib/auth.ts`) — never hardcode the API
origin. `oxlint` is the linter (see `.oxlintrc.json`); there is no ESLint
config.

## Conventions worth preserving

- Upsert-by-business-key (`stripeId`) is the pattern throughout ingestion —
  a rerun overwrites scalar fields rather than duplicating rows. Follow it
  for any new ingestion path.
- Data problems that are recoverable (duplicate records, an unmatched
  settlement total) become a logged `$io->warning`, not a thrown exception —
  reserve exceptions for things with no sane recovery, like a refund
  referencing a charge that doesn't exist at all.
- Comments in this codebase explain *why*, often citing the specific fixture
  row or data anomaly that forced a decision — match that style rather
  than describing *what* the code does.
