#!/bin/sh
set -e

# vendor/ is bind-mounted from the host (api/vendor), not a named volume, so
# it doesn't inherit the image's build-time `composer install` output — on a
# fresh clone the host directory is empty (gitignored) and shadows it
# entirely. Self-heal here rather than failing on the first `bin/console`
# call below.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

# By the time this runs, compose's `depends_on: db: condition: service_healthy`
# guarantees Postgres is accepting connections — no manual wait-for-db loop needed.

# Auto-creating the database is a dev/demo convenience — this repo's only
# real deployment target is `docker compose up` against a fresh, empty
# Postgres volume. A real prod database is provisioned deliberately
# (managed service, IaC, ...), never implicitly by the app container
# booting, so this step is skipped there.
if [ "$APP_ENV" != "prod" ]; then
    php bin/console doctrine:database:create --if-not-exists --no-interaction
fi

# --skip-if-exists makes this a no-op once the keypair exists. config/jwt/ is
# part of the bind-mounted api/ source, so the keys persist on the host across
# container restarts — this only actually generates them once, ever.
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

# Migrations DO run unconditionally, including in prod — unlike the two
# steps above, "apply pending migrations on deploy" is a normal, deliberate
# release step in most real systems, not a dev-only convenience.
#
# doctrine/migrations errors on `migrate` when zero migration classes exist yet
# (rather than no-op'ing), which is legitimately the case before 
# the first migration — guard so `docker compose up` still succeeds meanwhile.
if [ -n "$(find migrations -maxdepth 1 -name '*.php' 2>/dev/null)" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction
fi

# Seeding is the one that matters most to gate: it's this project's sample
# data, not a real system's. Left unconditional, it would re-import the
# App\DataFixtures records and reset admin@example.com's password back to
# "password" on every restart of a real deployment — exactly what a real
# system must never do to live data. (The fixtures bundle isn't even
# enabled in prod.) app:seed-ledger rather than doctrine:fixtures:load
# because it upserts instead of purging, so restarts keep the same rows.
if [ "$APP_ENV" != "prod" ]; then
    php bin/console app:seed-ledger --no-interaction
fi

# PHP's built-in server is single-threaded and explicitly documented by
# Symfony as dev-only — fine for this exercise's `docker compose up`
# bootstrap, but this line, not just the two steps above, is why this
# compose file has no real prod deployment path. A real one needs
# php-fpm+nginx (or a single-binary alternative like FrankenPHP) instead.
exec php -S 0.0.0.0:8000 -t public
