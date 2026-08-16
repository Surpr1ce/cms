# Phase 1 Quickstart: Public Website

**Feature**: `002-public-website` | **Date**: 2026-08-17

How to see the site and confirm it does what `spec.md` claims.

## Bring it up

```bash
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction

# The stylesheet. Downloads a pinned standalone binary on first run — no Node.
php bin/console tailwind:build --minify

symfony serve        # or: php -S 127.0.0.1:8000 -t public
```

Full database setup, including the `.env.test.local` file the test suite needs,
is in [`docs/setup.md`](../../docs/setup.md).

Open <http://127.0.0.1:8000/>. With the development fixtures loaded you should see
8 published articles, a menu with About us, Contact and Privacy, and nothing from
the 2 drafts or the 2 archived articles.

## The addresses

| Path | What it is |
| --- | --- |
| `/` | Published articles, newest first, 20 to a page |
| `/articles/{slug}` | An article |
| `/sections/{slug}` | A section, its subsections and its articles |
| `/topics/{slug}` | A label and its articles |
| `/{slug}` | A standalone page |

The full contract, including why the last one is safe, is in
[`contracts/routes.md`](contracts/routes.md) and
[ADR 8](../../docs/adr/0008-public-address-scheme.md).

## Validation scenarios

Each maps to a user story. All are covered by the automated tests; they are here
so a reviewer can confirm the behaviour directly.

### US1 — reading

1. Open `/`. Articles appear newest first with title, date, author and summary.
2. Follow one. The article opens at its own address with its full body.
3. `/?page=2` with more than 20 published articles shows the next page and a link
   back.

### US2 — unpublished work is invisible

The important one. With the fixtures loaded:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/articles/a-draft-nobody-has-finished-yet
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/articles/retired-the-first-api-design
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/articles/no-such-article-at-all
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/terms-of-service
```

All four must print `404` — a draft, something archived, an address that never
existed, and a draft page. Then confirm the *bodies* match, which is the part
that matters:

```bash
curl -s http://127.0.0.1:8000/articles/a-draft-nobody-has-finished-yet > /tmp/draft.html
curl -s http://127.0.0.1:8000/articles/no-such-article-at-all          > /tmp/missing.html
diff /tmp/draft.html /tmp/missing.html && echo "indistinguishable"
```

**Run this with the application in its production configuration.** In dev,
Symfony answers a 404 with its own exception page, which differs between the two
by design and tells you nothing about what a reader would see.

### US3 — sections and labels

1. `/sections/news` lists that section's published articles and links to its
   subsection.
2. `/topics/php` lists articles carrying that label.
3. A section holding only drafts renders as an empty section, not a 404.

### US4 — pages and the menu

1. The menu appears on every page in the order set by `menuOrder`.
2. `/our-team` shows a breadcrumb up to `/about-us`.
3. `/terms-of-service` — a draft page — is absent from the menu and returns 404.

### US5 — errors

1. `/nothing-here` renders the site's own not-found page, with the menu.
2. Nothing in it names a file, a class, a query or a version.
3. The status is 404, not 200.

## Run the checks

```bash
composer qa                                    # 379 tests, 780 assertions
vendor/bin/phpunit --testsuite functional      # the routes alone
```

## What this feature still does not give you

- No sign-in and no administration. Content is changed through fixtures or the
  console.
- No upload. Lead images reference filenames the catalogue holds; the files
  themselves are not there, which is why an article renders without its image
  rather than failing.
- No search, feeds, sitemap or caching.
