# Testing strategy

## Layout

| Directory | Kind | Boots kernel | Touches database |
| --- | --- | --- | --- |
| `tests/Unit/` | Domain logic | No | No |
| `tests/Integration/` | Repositories, services | Yes | Yes |
| `tests/Functional/` | Routes end to end | Yes | Yes |

The split is by cost and by what breaks the test, not by which class is under
test. A unit test that needs the container is an integration test that has been
mislabelled.

## Isolation

`dama/doctrine-test-bundle` wraps each test in a transaction and rolls it back
afterwards. Tests therefore never depend on execution order and never need manual
cleanup.

Fixtures are created per test with Foundry factories, not loaded globally:

```php
$article = ArticleFactory::createOne([
    'title' => 'Hello',
    'status' => ArticleStatus::Published,
]);
```

Building state inside the test that needs it keeps the test readable on its own
and stops one test's fixture changes from breaking another.

## What gets tested

**Unit** — status transitions and the rules that guard them, slug generation
including collisions and non-ASCII input, validation constraints.

**Integration** — repository queries return what they claim, particularly the
published-content scope; services persist correctly; upload handling writes the
file and rejects what it should.

**Functional** — for every route: the happy path, the anonymous-user redirect,
and the wrong-role 403. Admin routes without all three are considered untested.

## Rules

- One behaviour per test method, named for the behaviour (`testItRejectsPublishingWithoutContent`).
- Assert on observable outcomes, not on internals reached through reflection.
- Cover failure paths — unauthorised, invalid, not-found, boundary values.
  Happy-path-only coverage reports a number without providing assurance.
- Never weaken an assertion to make a test pass. A failing test is either a real
  defect or a wrong test; both need a decision, not a smaller assertion.

## Running

```bash
composer test                # full suite
composer test:coverage       # plus HTML report in var/coverage
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --filter testItRejectsPublishingWithoutContent
```

CI runs the suite against a PostgreSQL service container on every push and pull
request, so nothing passes by depending on local machine state.

## The browser check

```bash
symfony serve -d
node tools/browser-check.mjs             # CHROME_PATH=... to point at a browser
```

**Nothing in the suite above runs any JavaScript.** Feature 018 added about six
hundred lines of it — the search suggestions and the visual editor — and every
fault ever found in that code was found by driving a browser rather than by
`composer qa`: an editor thrown away on the next Turbo visit, a suggestion list
that refused to reopen after Escape, arrow keys walking a list nobody could see,
a Link button applying an address the sanitiser silently stripped at save time.
The suite was green through all of them.

So this exists, and it is deliberately **not** part of `composer qa`: it needs a
running site, a loaded database and a browser, which are three things the quality
gate must not require. Run it by hand before a release and after touching
anything in `assets/`. It signs in with the development fixture account, so point
it only at a development installation.

## Coverage

Coverage is measured and reported, but it is a diagnostic, not a target. A high
percentage achieved by exercising code without asserting on its behaviour is
worse than an honest lower number, because it hides the gap. Where coverage is
thin, the reason is recorded rather than papered over.

**PHP only.** The figure says nothing about `assets/`, which the browser check
above covers instead — and covers by behaviour rather than by line.
