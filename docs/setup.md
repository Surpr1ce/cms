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
states, 6 pages and 6 files.

The files are real images, drawn by GD rather than committed to the repository —
1200×800, two flat bands of colour, a different picture per file and the same one
on every load. They are deliberately not photographs: nobody should mistake
development data for real content, and they are large enough that every derived
size is a genuine reduction.

Loading the fixtures also removes what the previous dataset left on disk, so the
uploads directory holds exactly what the catalogue holds. Run it as often as you
like.

The test suite uses `var/test-uploads`, not `var/uploads`, so running it never
touches anything you uploaded by hand.

## Signing in

All four fixture accounts can sign in, using the password in
`UserFactory::DEVELOPMENT_PASSWORD` — written openly in the repository, because
an account whose password anybody can read is one nobody can mistake for a real
one.

| Account | Role |
| --- | --- |
| `admin@example.com` | administrator |
| `editor@example.com` | editor |
| two generated addresses | author |

On a real installation, where fixtures are never loaded:

```bash
php bin/console app:create-administrator you@example.com "a-long-enough-password" "Your Name"
```

It creates the account, or promotes and re-passwords an existing one. Minimum
twelve characters. The password is never echoed back.

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

## If the styling looks wrong

**Do not run `asset-map:compile` on a development machine**, and delete
`public/assets` if somebody has.

That command writes the compiled assets for production. The web server serves
files in `public/` directly, so once that directory exists the browser gets
whatever the stylesheet looked like the last time it was compiled — and every
change since is invisible, while `var/tailwind/app.built.css` goes on being
correct and reassuring.

This cost two rounds of "it still looks wrong" against a stylesheet that was
eight features old. The fix is one line:

```bash
rm -rf public/assets      # PowerShell: Remove-Item -Recurse -Force public\assets
```

If Tailwind classes added to a template do not appear at all,
`php bin/console tailwind:build` rebuilds the stylesheet — but check what is
actually being served first:

```bash
curl -s localhost:8000 | grep -o '/assets/styles/[^"]*\.css'
```

## Operator commands

```bash
# Create or promote an administrator. The only way in before any screen exists.
php bin/console app:create-administrator you@example.com

# Remove resized images whose originals are no longer catalogued.
# Safe at any moment: a derived image is a cache and is made again on request.
php bin/console app:media:prune-derived --dry-run
php bin/console app:media:prune-derived
```

There is deliberately no command that removes *original* uploads. A derived image
can be remade; an original is the only copy of somebody's file, and a command
that removed uncatalogued ones would destroy an upload whose database row failed
to save.
