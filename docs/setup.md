# Local setup

Two supported paths for the database. Both are verified on this machine; the
native one is the default because it holds the working databases. See
[ADR 7](adr/0007-docker-is-available-after-all.md).

**If you only want to look at the application, skip all of this:**

```bash
docker compose up -d --build          # then open http://localhost:8080
```

That builds the whole site into one container, waits for its own PostgreSQL,
migrates, loads the demo content and serves it. It needs no PHP, no Composer and
no database on your machine. `admin@example.com` / `development-only` signs you in.
See [ADR 15](adr/0015-a-container-for-showing-the-application.md) for what it is
and, more importantly, what it is not: a demonstration of the application rather
than a deployment of it, carrying development dependencies on purpose so that it
can seed itself.

The rest of this file is how the project is *developed*, which is natively.

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

`compose.override.yaml` also defines a Mailpit container for outbound mail. It
starts with the stack and is unused: `MAILER_DSN` is `null://null` everywhere, so
the one email this application sends goes nowhere. Pointing it at Mailpit means
pinning its ports and setting `MAILER_DSN=smtp://127.0.0.1:<port>`.

## Path C — the whole application in Docker

```bash
docker compose up -d --build          # http://localhost:8080
docker compose logs -f app            # migrations, seeding, then the server
docker compose down                   # stop, keeping the data
docker compose down -v                # stop and forget everything, including uploads
```

The `app` service is built from [`Dockerfile`](../Dockerfile): FrankenPHP, PHP 8.4
with `intl`, `pdo_pgsql`, `gd`, `exif`, `opcache` and `zip`, the assets compiled
and the cache warmed at build time. On start it waits for the database's
healthcheck, migrates, and — **only if the database holds no accounts** — loads the
demo fixtures. So restarting the stack keeps whatever you created; `down -v` is
what forgets it.

This path does not touch the native PostgreSQL or the `app`/`app_test` databases
on your machine. It runs its own PostgreSQL in the `database` container with its
own volume, which is why its content and the content you see through
`composer serve` are two different sites.

Verified the same way the native path is: `node tools/browser-check.mjs
http://localhost:8080` passes all twenty-one checks against the container.

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
composer serve               # development: http://127.0.0.1:8000, debug toolbar, no cache to warm
composer serve:prod          # what a visitor gets: no toolbar, compiled assets, warm cache
composer demo:data           # reload the fixtures — REPLACES the development database contents
```

Both serve scripts use the built-in PHP server through
[`tools/serve-router.php`](../tools/serve-router.php), and three details in them
were each found the hard way rather than reasoned out, so leave them alone unless
you have checked the replacement in **both** environments:

- **The router exists because neither half works without it.** With no router
  script, `/articles/something` is a 404 before Symfony sees it; with
  `public/index.php` as the router, every compiled asset under `/assets/` goes
  through Symfony too — which is invisible in development, where AssetMapper
  serves those files through a controller, and answers 500 for every one of them
  in production.
- **`Composer\Config::disableProcessTimeout`** — Composer kills a script after
  300 seconds, so without it the server stops five minutes in, which is a
  memorable thing to discover during a demonstration.
- **`-d variables_order=EGPCS`** on the production script. Symfony reads `APP_ENV`
  from `$_SERVER`/`$_ENV`, and the built-in server does not put the process
  environment there by default — so without it the "production" server quietly
  serves the development environment, debug toolbar and all.

`symfony serve` works too if you have the Symfony CLI, and needs none of the
above; it is simply not installed on this machine.

**What you will see depends on how much has been built.** Check
[`status.md`](status.md) before assuming a blank page is a fault — as of feature
001 the project had a complete content model and no routes at all, so every URL
returned a 404 by design.

## If every request answers 400

The site answers only the hosts named in `SYMFONY_TRUSTED_HOSTS`, and `.env` sets
that to `localhost` and `127.0.0.1` — the two you develop against. Reaching the
site by any other name, a machine name or a LAN address, is refused with a 400.

That is deliberate and it is not only a nicety. Symfony builds absolute addresses
from the host a request claims to be for: canonical tags, the sitemap, the feed,
and the password-reset link. Left unrestricted, anybody can POST the reset form
with `Host: attacker.example` and have this site send a real person a real email
whose link points somewhere else.

**A deployment must set it to the hostname the site is actually served under**,
in `.env.local` or as a real environment variable:

```bash
SYMFONY_TRUSTED_HOSTS='^(www\.)?example\.com$'
```

Getting it wrong is a loud 400 on every request rather than a quiet hole, which
is the right way round. If a proxy sits in front, set `trusted_proxies` too.

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
