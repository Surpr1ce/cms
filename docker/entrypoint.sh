#!/bin/sh
set -e

#
# What has to happen between "the database is accepting connections" and "the site
# is worth looking at".
#
# compose already waits for the database's healthcheck, so this does not race it.
# What it does do is make starting the stack twice mean the same thing as starting
# it once: migrations are idempotent by design, and the fixtures are loaded only
# into an empty database, so anything written during a demonstration survives a
# restart.
#

echo "→ migrating"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

if [ "${DEMO_SEED:-1}" = "1" ] && php /app/docker/needs-seeding.php; then
    # The fixtures and Foundry are registered for dev and test only, so the
    # seeding runs in the development environment while the site itself is served
    # in production. Same database, same connection — only the kernel differs.
    echo "→ seeding the demonstration content"
    php bin/console --env=dev doctrine:fixtures:load --no-interaction
else
    echo "→ database already holds accounts, leaving it alone"
fi

echo "→ serving on ${SERVER_NAME:-:8080}"

exec "$@"
