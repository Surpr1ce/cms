# Phase 1 Quickstart: Core Content Model

**Feature**: `001-core-content-model` | **Date**: 2026-08-16

How to bring this feature up from nothing and confirm it does what `spec.md`
claims. Written to be runnable by someone who has just cloned the repository.

This is a validation guide, not an implementation guide — what the code does is
in `contracts/domain-api.md` and `data-model.md`; the ordered work is in
`tasks.md`.

## Prerequisites

| Requirement | Check |
| --- | --- |
| PHP 8.4 with `ctype` and `iconv` | `php -v` |
| Composer | `composer --version` |
| PostgreSQL 16, running | `Get-Service postgresql-x64-16` |
| Credentials in `.env.local` | `DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"` |

PostgreSQL runs natively on the development machine; Docker is not available. See
[ADR 3](../../docs/adr/0003-postgresql-natively-instead-of-docker.md).

## Bring the schema up

```bash
composer install

php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction
```

The test suite uses a separate database — `app_test`, from the `dbname_suffix`
already configured in `config/packages/doctrine.yaml` — which has to exist and
carry the same schema:

```bash
php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate --no-interaction
```

**This satisfies SC-006**: an empty database reaches the full shape of the model
through the migration alone, with nothing edited by hand.

## Confirm the schema matches the mapping

```bash
php bin/console doctrine:schema:validate
```

Both lines must report OK. A "database schema is not in sync" result after a
clean migrate means the migration and the entity mapping have drifted — regenerate
rather than patching the migration, which is immutable once committed.

Inspect the constraints that carry the deletion rules:

```bash
php bin/console dbal:run-sql "SELECT tc.table_name, tc.constraint_name, rc.delete_rule
                              FROM information_schema.table_constraints tc
                              JOIN information_schema.referential_constraints rc
                                ON rc.constraint_name = tc.constraint_name
                              WHERE tc.constraint_type = 'FOREIGN KEY'
                              ORDER BY tc.table_name"
```

Expected: `RESTRICT` on `article.author_id`, `media.uploaded_by_id` and
`page.parent_id`; `SET NULL` on the two `featured_image_id` columns,
`article.category_id` and `category.parent_id`; `CASCADE` on both `article_tag`
columns. The full table is in `data-model.md`.

## Run the tests

```bash
composer test                              # everything
vendor/bin/phpunit --testsuite unit        # no container, no database — fast
vendor/bin/phpunit --testsuite integration # repositories and services
```

The `unit` and `integration` suites are added by this feature; before it, only a
single combined suite existed (see `research.md`, decision 14).

## Run the full quality gate

```bash
composer qa
```

Style, Rector in dry-run, PHPStan at level max, and the whole test suite. **This
is SC-007** and, per constitution principle III, it passes with nothing relaxed —
no lowered level, no baseline, no suppression, no skipped test. If it does not
pass, the feature is not done, and that is reported rather than worked around.

## Validation scenarios

Each maps to a user story in `spec.md`. All are covered by the automated tests;
they are written out here so the behaviour can also be confirmed by hand in
`php bin/console doctrine:query:dql` or a scratch script when a reviewer wants to
see it directly.

### US1 — publication lifecycle

1. Create an article. It is `draft` with `publishedAt` null.
2. Publish it. Status becomes `published`; `publishedAt` is the passed-in time.
3. Unpublish, then publish again with a *different* time. `publishedAt` is
   **unchanged** — this is SC-005, and it is the single most important assertion
   in the feature.
4. Archive it, then restore it. It is `draft` again, not `published`.
5. Publish an article whose body is an empty string → `ContentNotPublishable`.
6. Publish an already-published article → `InvalidStatusTransition`.

### US2 — addresses

1. Title `Hello, World!` → slug `hello-world`.
2. Title `Žltý kôň` → slug contains only `[a-z0-9-]`.
3. A second article with the same title → `hello-world-2`, both retrievable.
4. A *page* titled `Hello, World!` → also `hello-world`, because uniqueness is
   per table.
5. Title `!!!` → a non-empty generated slug rather than a refusal.
6. `assignSlug()` on published content → `SlugIsFrozen`.

### US3 — organisation

1. Assign a second category to an article → the first is replaced, not added.
2. Delete a category holding articles → the articles survive with
   `category_id` null, and the category's children point at its former parent.
3. Delete a tag → the articles survive, the join rows are gone.

### US4 — pages

1. Nest a page under another, set menu positions, list siblings → they come back
   in `menu_order`.
2. Delete a page that has children → `PageStillHasChildren`, nothing removed.
3. Walk a page through the full lifecycle → identical to US1.
4. Set a page as its own grandparent → `HierarchyWouldBeCircular`.

### US5 — files

1. Catalogue a file whose original name is `../../evil.php` → the stored filename
   is a generated hex token with a safe extension; the original is kept only as
   display text.
2. Attach a file with no alt text as a lead image → `MediaMissingAltText`.
3. Delete a file used as a lead image → the article survives with no lead image,
   in the database *and* in the already-loaded entity.

### US6 — accounts

1. Create a second account with an existing email → refused.
2. Delete an account that authors articles → `UserStillOwnsContent`.
3. Archive that account's articles, then try again → still refused, because
   archiving is not a release of ownership.
4. Delete an account owning nothing → removed.

## What this feature does *not* let you do

Stated so nobody looks for it: there is no page to open, no route to visit, no
login, no admin screen and no JSON endpoint. `symfony serve` starts a working
application with an empty front page, exactly as it did before this feature. The
content model is reachable from tests, fixtures and the console only.

Consequently there are **no functional tests** in this feature. `docs/testing.md`
requires an anonymous-user case and a wrong-role case for every route; with no
routes, that requirement has nothing to bind to yet. It is not waived — it starts
applying to the first feature that adds one.