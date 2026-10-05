# Payments dashboard

A dashboard for merchants selling through a Stripe Connect platform to see
their sales, fees, and payouts without logging into Stripe. It's a demo of a
Symfony 8 JSON API with a React front end, built around a small but
deliberately messy sample of Stripe Connect data. This demo was designed for developers to get to know a stripe backed platform, this is not production ready and should only be used for the purpose of learning a ledger based system and the stripe platform.

## The scenario

A marketplace platform takes card payments through Stripe Connect on behalf
of the organisations selling on it, keeping an application fee on each sale.
Those organisations otherwise have to log into Stripe to understand their
money. The dashboard reports, from a sample Stripe Connect data extract:
gross sales, the platform's fee and Stripe's fee separately, refunds issued,
net earned, paid out to date, and pending broken down by availability
date. The extract is deliberately imperfect - as real provider data often
is - with duplicate deliveries and payouts that don't line up neatly with
availability dates.

## Quick start

```
docker compose up
```

That's it - one command builds the images, creates the database, runs
migrations, seeds it from the sample data, and starts both services. No local
PHP/Node/Postgres install needed, and no manual `.env` setup - it works from a
plain `git clone`.

- **Dashboard**: http://localhost:5173
- **API**: http://localhost:8000/api/dashboard
- **API docs**: http://localhost:8000/api/doc.json (OpenAPI 3 JSON spec)

**Login**: `admin@example.com` / `password` - seeded automatically, not a real
secret (see Security).

Run it again any time - it won't re-import the data or regenerate keys.
Full reset: `docker compose down -v`.

### Running the tests

```
docker compose up -d db api
docker compose exec api php bin/phpunit
```

No separate setup step - `tests/bootstrap.php` creates the test database,
runs migrations, and generates a JWT test keypair automatically (all
idempotent, safe to run repeatedly). 71 tests, 305 assertions:

- `tests/Unit` - no database, no kernel: `--testsuite=unit`.
- `tests/Application` - real database/HTTP-backed tests (seeding, the
  dashboard endpoint, login): `--testsuite=application`.

### Code quality checks

```
docker compose exec api composer bin psalm install   # one-time, pulls in Psalm
docker compose exec api composer check                # phpcs (PSR-12) + Psalm
```

Psalm is a bin-plugin-managed dev tool (`tools/psalm/`, kept out of the main
`vendor/` install), so it needs that one install step the first time.

## Architecture

- **`api/`** - Symfony 8.1, a pure JSON API. No server-rendered pages.
- **`web/`** - a React + Vite SPA that talks to that API over HTTP, on its
  own origin (CORS, not a dev proxy) - the way a real production split would
  look.
- **Postgres** - everything is stored here. Data gets in by seeding only,
  as it stands (see below).

### Sample data and seeding

The sample Stripe Connect extract lives in DoctrineFixturesBundle classes
under `api/src/DataFixtures/` - one per record type (`ChargeFixtures`,
`RefundFixtures`, `DisputeFixtures`, `PayoutFixtures`) plus
`OrganisationFixtures` for the organisation and demo login, with
`getDependencies()` enforcing the order they have to load in.

The records are kept in their **raw, provider-shaped form** (Stripe
`charge`/`refund`/`dispute`/`payout` arrays, anomalies included), not as
pre-built entities. Loading them goes through exactly the same gateway →
`PaymentService` → settlement reconciliation path a real ingest would, so
the fixtures exercise the pipeline rather than sidestep it. Two ways in,
both driving the same `LedgerSeeder` service:

```
docker compose exec api php bin/console app:seed-ledger --dry-run   # what would be seeded: counts, duplicates collapsed
docker compose exec api php bin/console app:seed-ledger             # upsert by stripeId, never purges - what `docker compose up` runs
docker compose exec api php bin/console doctrine:fixtures:load      # standard bundle flow: purge, then reload
```

A test seeds through each and asserts they produce identical totals.

## Security considerations

- Every `/api/*` route requires a bearer JWT except `/api/login` and
  `/api/doc.json` - enforced by the firewall, confirmed with a real
  unauthenticated request returning `401`.
- The seeded demo credentials are not a secret, deliberately - this is a
  demo, reseeded on every dev boot (never in `APP_ENV=prod`).
- Passwords are hashed; JWTs are short-lived (1 hour) and signed with a
  keypair generated per-deployment, never committed.
- CORS is locked to the known frontend origin via env var, not wildcarded.
- All money is handled as integer minor units throughout - no floats, no
  rounding drift.

## Known limitations

- Single organisation, one shared login - no individual accounts or roles.
- No multi-currency support (the fixture is single-currency throughout).
- `docker compose up` is a dev bootstrap, not a deployment path - it serves
  through PHP's built-in dev server (Symfony documents this as unfit for
  production traffic); a real deployment needs php-fpm+nginx or similar in
  its place, and its own provisioned database rather than one created
  implicitly by the app booting.
