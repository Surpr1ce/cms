# 15. A container for showing the application, not for developing it

- **Status**: Accepted
- **Date**: 2026-08-17
- **Extends**: [ADR 7](0007-docker-is-available-after-all.md)

## Context

[ADR 7](0007-docker-is-available-after-all.md) recorded that Docker works on this
machine and that `compose.yaml` — a PostgreSQL service from a Symfony Flex recipe
— is verified. It also recorded that the application itself was not containerised:
`compose.yaml` started a database and nothing that served a page.

That produced a reasonable question from somebody starting the stack: *the
container is called `cms`, so why does starting it only give me PostgreSQL?*
Because the recipe never described the application. Nothing was wrong; the name
promised more than the file did.

Meanwhile, showing this application to anybody meant installing PHP 8.4 with
`intl`, `pdo_pgsql` and `gd`, PostgreSQL 16, Composer, and running three commands
in the right order.

## Decision

**Containerise the application, for demonstrating it and for reading it — not for
developing it.**

`Dockerfile` builds one image on `dunglas/frankenphp:php8.4`; `compose.yaml` gains
an `app` service that waits for the database's healthcheck, migrates, seeds an
empty database with the demo fixtures, and serves on `http://localhost:8080`.
`docker compose up -d --build` is the whole of the instructions.

Three choices inside it are worth recording, because each is the sort of thing a
later reader would otherwise undo:

**FrankenPHP rather than php-fpm behind nginx.** One process and one container
instead of two of each and a socket between them. It is also what Symfony's own
Docker skeleton uses, so the shape is not invented here.

**Development dependencies are installed on purpose.** A production image would
not. The demo content comes from `DoctrineFixturesBundle` and Foundry, which
`config/bundles.php` registers for `dev` and `test` only — so a container that
seeds itself needs them present. The site is served with `APP_ENV=prod`; only the
seeding step runs `--env=dev`, against the same database over the same connection.
This is a demonstration of the application, not a deployment of it, and the image
says so at the top.

**Native PostgreSQL and `composer serve` remain the development default**, for
exactly the reasons ADR 7 gave: the native instance holds the migrated databases
and the fixtures, and the recipe's override publishes the database on an ephemeral
host port. Nothing about this ADR changes how the project is developed. The
container is a second way in, kept honest by the same browser checks.

## Consequences

**"How do I run it" now has two answers, and both are verified.** `composer serve`
for development, `docker compose up -d` for a visitor's view.
`tools/browser-check.mjs` passes twenty-one checks against the container, the same
as against a native production build — which is the only reason this record can
claim the container works rather than that it starts.

**The image is not a deployment artefact.** It carries dev dependencies, a
demonstration `APP_SECRET` default and `MAILER_DSN=null://null`. A real deployment
needs `composer install --no-dev`, a real secret from its own environment, a real
mailer, and a reverse proxy terminating TLS — and `docs/status.md` already records
the first two as release-checklist items.

**Building it found three faults that nothing else had.** The suite cannot see any
of them, because none is about the application's rules:

1. **`gd` was missing** and the fixtures draw placeholder images. CI installs
   `ctype, iconv, pdo_pgsql, intl` and passes, because no test draws an image —
   the first build died three seconds into loading the demo content.
2. **`assets/vendor/` is gitignored**, so `.dockerignore` excluded it and
   `asset-map:compile` failed on a missing `@hotwired/stimulus`. The image runs
   `importmap:install` rather than copying whatever a developer's machine holds,
   which makes the build reproducible instead of dependent on it.
3. **The emptiness check queried the wrong table.** `app_user`, not `user` — the
   entity renames it because `user` is reserved in PostgreSQL. The query threw, the
   exception was read as "nothing there", and every `docker compose restart`
   purged the database and reloaded the fixtures. Anything written during a
   demonstration would have vanished on the next restart, which is the failure that
   would have been noticed at the worst possible moment.

**A fourth fault was already there and this exposed it.** Getting the container to
serve compiled assets is what led to `tools/serve-router.php`, and to finding that
`tools/browser-check.mjs` had only ever been able to check the development
environment: it signed in with `form.submit()`, which does not fire the event
Symfony's stateless CSRF controller listens for. Development tolerated that;
production did not.
