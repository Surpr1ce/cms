# Implementation Plan: Public Website

**Branch**: `002-public-website` | **Date**: 2026-08-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-public-website/spec.md`

## Summary

Put the content model on screen: a home listing, article pages, section and label
listings, standalone pages, a menu, and error pages that belong to the site.

The technical approach in one sentence: **controllers do not query, they ask the
repositories that already know what "published" means, and the one rule that
cannot be got wrong is enforced by never having a code path that can return
unpublished content in the first place.**

Concretely:

- Every public route resolves content through a repository method whose name
  contains `Published`. There is no controller that calls `findOneBySlug()` and
  then checks a status — a check that can be forgotten is not a guarantee.
- A missing or unpublished slug produces the same `NotFoundHttpException` by the
  same path, so FR-002's indistinguishability is structural rather than
  maintained.
- Listings fetch one item more than they display. The extra row answers "is there
  a next page" without a second `COUNT` query, which is how SC-007 is met.
- Author and section are join-fetched into listing queries. Tags are not, because
  listings do not show them.

## Technical Context

**Language/Version**: PHP 8.4, `declare(strict_types=1)` everywhere

**Primary Dependencies**: Symfony 8.1, Twig, AssetMapper, `symfonycasts/tailwind-bundle`
(a standalone binary, no Node), Doctrine ORM 3. **No new Composer dependency.**

**Storage**: Read-only use of the schema feature 001 created. **No migration.**

**Testing**: PHPUnit 13. This is the first feature with routes, so it is the first
with a `tests/Functional/` suite — and `docs/testing.md`'s rule that every route
gets an anonymous-user case starts applying here.

**Target Platform**: Linux in CI, Windows 11 for development. Docker is now
available and `compose.yaml` is verified; see
[ADR 7](../../docs/adr/0007-docker-is-available-after-all.md).

**Project Type**: Server-rendered web application. This feature is its delivery
layer.

**Performance Goals**: A listing page must issue a number of queries that does not
grow with the number of items on it (SC-007). Asserted by a test that counts
queries through the profiler, not by inspection.

**Constraints**:

- PHPStan level max with `checkUninitializedProperties: true`.
- `strict_variables: true` in the test environment, so a template referencing an
  undefined variable fails the suite rather than rendering an empty string.
- No Node build step. Tailwind runs from a downloaded standalone binary.

**Scale/Scope**: 5 controllers, 1 Twig extension, ~12 templates, 1 pagination
value object, 4 new repository methods. Roughly 10 unit and 40 functional tests.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Verdict |
| --- | --- | --- |
| **I. Domain Independent of Delivery** | Nothing under `src/Entity/` or `src/Service/` gains an HTTP or Twig dependency | **Pass** — this feature adds only `Controller/` and `Twig/`. The `Paginator` value object lives in `src/Service/` and knows nothing of requests; the controller reads the query parameter and hands it a number. |
| **II. Specification Before Implementation** | Specification exists first | **Pass** — `spec.md`, 16/16 checklist |
| **III. Quality Gate Is Not Negotiable** | `composer qa` passes untouched | **Pass, planned** |
| **IV. Tests Prove Failure Paths** | Every route has an anonymous-user case; every invariant has a refusal test | **Pass, and this is the feature where it bites.** Until now there were no routes, so the rule had nothing to bind to. Every route here gets an anonymous case, and every content type gets a draft-is-404 case. The wrong-role case does not apply because no route requires a role — there is no sign-in yet, and inventing one to satisfy the letter of the rule would be worse than saying so. |
| **V. Decisions Are Recorded** | Cross-layer decisions become ADRs | **Pass, with work** — the address scheme is one: reversing it changes routes, templates and every existing link. Planned as ADR 8. |
| **VI. Status Is Reported Honestly** | `docs/status.md` reflects reality | **Pass** — updated in the same change, including the fact that FR-024 is inherited by a later feature rather than discharged here. |

**Post-Phase 1 re-check**: passed. One thing worth a reviewer's attention: the
menu is built by a Twig extension that queries the database during rendering.
That is a delivery-layer component reading through a repository, which principle I
allows, but it does mean a template can trigger a query. It is one query per
request and it is tested; anything more elaborate would be a cache, and caching is
out of scope.

## Project Structure

### Documentation (this feature)

```text
specs/002-public-website/
├── plan.md, spec.md, quickstart.md
├── contracts/routes.md          # the public address scheme
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
src/
├── Controller/
│   ├── HomeController.php          # /
│   ├── ArticleController.php       # /articles/{slug}
│   ├── CategoryController.php      # /sections/{slug}
│   ├── TagController.php           # /topics/{slug}
│   └── PageController.php          # /{slug}   — declared last, on purpose
├── Service/Pagination/
│   ├── Paginator.php               # pure: page number -> offset, limit, flags
│   └── Page.php                    # the result: items plus navigation facts
├── Twig/
│   ├── MenuExtension.php
│   └── MenuRuntime.php             # published pages, nested, in menu order
└── Repository/                     # four methods added, no new class

templates/
├── base.html.twig                  # existing, replaced
├── public/
│   ├── layout.html.twig
│   ├── home.html.twig
│   ├── article/show.html.twig
│   ├── category/show.html.twig
│   ├── tag/show.html.twig
│   └── page/show.html.twig
├── components/
│   ├── _menu.html.twig
│   ├── _article_card.html.twig
│   ├── _pagination.html.twig
│   └── _tag_list.html.twig
└── bundles/TwigBundle/Exception/
    ├── error404.html.twig
    └── error.html.twig

tests/Functional/                   # the suite that has been empty until now
├── HomeControllerTest.php
├── ArticleControllerTest.php
├── CategoryControllerTest.php
├── TagControllerTest.php
├── PageControllerTest.php
├── ErrorPageTest.php
└── UnpublishedContentIsInvisibleTest.php   # US2, gathered in one place
```

**Structure Decision**: the three-layer arrangement is unchanged. What this
feature adds is entirely `Controller/`, `Twig/` and `templates/` — the boundary
`CLAUDE.md` describes as "thin, delegates to services". The one piece under
`src/Service/` is pagination arithmetic, which is domain-shaped and has no HTTP
in it.

`tests/Functional/UnpublishedContentIsInvisibleTest.php` gathers every visibility
assertion in one file rather than scattering them across the per-controller
tests. The rule is one rule, it is the one that cannot be got wrong, and a
reviewer should be able to read it in one place.

## As built

Delivered and passing: **379 tests, 780 assertions** across the whole project, of
which 73 are the functional suite this feature created. PHPStan at level max with
no baseline, no ignore comment and no skipped test.

Four things the implementation settled that this plan left open:

1. **`featuredImage` was already on `PublishableContent`,** so both articles and
   pages render a lead image with no extra work.
2. **`Slug::ROUTE_PATTERN`** was added next to `Slug::PATTERN` rather than
   repeating the expression in five route attributes.
3. **The visibility tests run with debug off.** Not a detail: with debug on,
   Symfony's own exception page answers a 404, and the question the tests exist
   to ask — can a reader tell a draft from nothing — cannot be asked at all.
4. **`/api` is reserved but legitimately returns 200,** because API Platform
   answers it. The reserved-prefix test asserts on content rather than status.

`research.md` is absent. The Phase 0 step exists to resolve unknowns, and this
feature had none worth a document: every technical choice was either fixed by the
constitution (Twig, AssetMapper, Tailwind, no Node) or settled inside `plan.md`
and `contracts/routes.md`. Writing an empty research document to satisfy a
checklist would be the kind of artifact the constitution's "a phase that left no
artifact did not happen" rule is meant to prevent, not the kind it wants.

`tasks.md` was written **alongside** the implementation rather than before it —
a real departure from the workflow, and stated at the top of that file rather
than smoothed over.

## Complexity Tracking

| Choice | Why needed | Simpler alternative rejected because |
|--------|------------|--------------------------------------|
| Fetching `limit + 1` rows to detect a next page | SC-007 asks that queries not grow with page size; a `COUNT` per listing is a second query per page for information one extra row already carries | A `COUNT(*)` companion query was rejected on that ground, and because the count and the page can disagree under concurrent publishing |
| A `Paginator` value object rather than clamping inside each controller | Four listings share the same boundary rules — first page, last page, beyond the end, a negative or non-numeric page — and FR-026 requires each to be tested. Tested once in a unit test beats four times through HTTP | Inline clamping was rejected because the interesting cases would then only be reachable through a functional test, which is the slow place to test arithmetic |
| A catch-all `/{slug}` route for standalone pages | "About" reads better at `/about-us` than at `/pages/about-us`, and pages are the content most often linked from outside | Prefixing pages too was the safe option and is rejected on reader-facing grounds; the risk it avoids is handled by route ordering and a slug pattern requirement, both of which are tested |
