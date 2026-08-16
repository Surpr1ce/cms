# Local setup

Two supported paths for the database. Both are verified on this machine; the
native one is the default because it holds the working databases. See
[ADR 7](adr/0007-docker-is-available-after-all.md).

## Prerequisites

| Requirement | Check |
| --- | --- |
| PHP 8.4 with `ctype`, `iconv`, `intl`, `pdo_pgsql` | `php -v` |
| Composer 2 | `composer --version` |
| PostgreSQL 16 — natively or through Docker | see below |

## Path A — native PostgreSQL (default)

```bash
Get-Service postgresql-x64-16        # should report Running
```

`.env.local`:

```
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

`.env.test.local` — **required, and easy to miss.** Symfony deliberately does not
load `.env.local` when `APP_ENV=test`, so the test environment falls back to the
placeholder credentials in `.env` and cannot connect. Both files are gitignored.

```
DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
```

Doctrine appends `_test` to the database name in the test environment, so the
suite actually runs against `app_test` while the URL names `app`.

## Path B — PostgreSQL in Docker

```bash
docker compose up -d database
docker compose port database 5432     # prints the ephemeral host port
```

`compose.override.yaml` publishes the container on a host port the daemon picks,
so it never collides with a native instance on 5432 — and `DATABASE_URL` has to
be updated after every `up`. Pin a fixed port in `compose.override.yaml` if you
work this way regularly; that is a deliberate change, not a default.

The compose password is the recipe default `!ChangeMe!` unless `POSTGRES_PASSWORD`
is set.

```bash
docker compose stop                   # stop, keeping the data volume
docker compose ps                     # what is running, and on which port
```

`compose.override.yaml` also defines a Mailpit container for outbound mail. It is
unused until a feature sends any.

## Bring the application up

```bash
composer install

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction

php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

The fixtures build 4 accounts, 3 sections, 5 labels, 12 articles across all three
states, 6 pages and 6 files. Sign-in does not exist yet, so the accounts are
author attributions rather than credentials you can use.

## Verify

```bash
php bin/console doctrine:schema:validate    # mapping and schema both OK
composer qa                                  # style, Rector, PHPStan, tests
```

## Run the site

```bash
symfony serve                # or: php -S localhost:8000 -t public
```

**What you will see depends on how much has been built.** Check
[`status.md`](status.md) before assuming a blank page is a fault — as of feature
001 the project had a complete content model and no routes at all, so every URL
returned a 404 by design.
