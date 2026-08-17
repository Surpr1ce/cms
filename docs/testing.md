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

**Architecture** — `tests/Unit/Architecture/` asserts the rules `CLAUDE.md`
states in prose, because prose is not a gate. It boots nothing and reads the
source as text, so it runs in a tenth of a second inside the unit suite:

| File | What it fails on |
| --- | --- |
| `LayeringTest` | an import that points outwards — HTTP or Twig inside `Entity/`, `Repository/`, `Search/` or `Service/`; a repository leaking a `QueryBuilder`; a query built in a controller; a `Form/Command` importing anything it could act with; a directory under `src/` with no row in the matrix |
| `DesignPrinciplesTest` | an action over 25 lines of code; a class over seven constructor dependencies; a reach for the container; mutable static state; a class in a ruled layer that is neither `final` nor deliberately `abstract` |

Both encode the *reason* for each rule beside it, and each exception to a rule is
a commented decision rather than a hole. They catch the crossing that is
declared, which is how a boundary is broken in practice; the judgement calls a
text scan cannot make — a rule living in the wrong layer, an invariant bypassed,
the same rule written twice with nothing tying the copies together — are what
`.claude/agents/architecture-guardian.md` is for, run before a merge.

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
vendor/bin/phpunit tests/Unit/Architecture     # the layer rules alone, no database
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
