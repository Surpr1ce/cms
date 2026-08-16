---

description: "Task list for feature 002 — public website"
---

# Tasks: Public Website

**Input**: Design documents from `/specs/002-public-website/`

## A deviation to report before anything else

**This list was written alongside the implementation, not before it.** The
constitution's workflow puts `/speckit-tasks` between planning and execution, and
that step was skipped: the specification, the plan and the route contract were
written first, then the code, and this list was assembled from what was actually
built.

That is a real departure and it costs something. A task list written first is a
commitment that can be checked; one written afterwards can only describe. What it
did **not** cost is traceability — every requirement in `spec.md` is either
implemented and tested or listed under "Not done" below, and `plan.md` carries an
As-built section for what the implementation settled differently.

Recorded here rather than quietly presented as a plan that was followed.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 Pin the Tailwind binary in `config/packages/symfonycasts_tailwind.yaml` (`v4.3.3`). Unpinned, the CSS a contributor builds and the CSS CI builds can differ, and a visual change nobody made is the hardest kind to trace
- [x] T002 Write `assets/styles/app.css` — the Tailwind entry point, the theme tokens, and a `.prose` block scoped so that markup from an editor cannot reach the site's own chrome
- [x] T003 Declare `app.site_name` and `app.articles_per_page` in `config/services.yaml`, and expose `site_name` as a Twig global so the error templates — which have no controller of ours — can use it
- [x] T004 Add a `tailwind:build` step to `.github/workflows/ci.yml`. The stylesheet is built into `var/`, which is not committed; without this step CI renders pages whose stylesheet does not exist

## Phase 2: Foundational

- [x] T005 Add `Slug::ROUTE_PATTERN` — the same rule as `Slug::PATTERN` without the delimiters, derived rather than written twice, so a route cannot accept a shape the entity refuses
- [x] T006 [P] Create `src/Service/Pagination/Paginator.php` — page-number normalisation, offset, and the fetch-one-extra-row trick that answers "is there a next page" without a `COUNT`
- [x] T007 [P] Create `src/Service/Pagination/ResultPage.php` — the items plus the two navigation facts, and deliberately no total
- [x] T008 Bind `$perPage` explicitly in `config/services.yaml`; autowiring cannot guess an int
- [x] T009 Add the listing queries to `ArticleRepository` — `findPublishedPage()`, `findPublishedPageByCategory()`, `findPublishedPageByTag()`, `findOnePublishedBySlugWithRelations()` — each join-fetching the author and section a listing renders, each routed through the published scope that already existed
- [x] T010 Add `findPublishedForMenu()` and `findOnePublishedBySlugWithRelations()` to `PageRepository`
- [x] T011 [P] Write `tests/Unit/Service/Pagination/PaginatorTest.php` — every boundary in FR-026, tested as arithmetic rather than through HTTP

## Phase 3: US1 — reading (P1)

- [x] T012 `templates/base.html.twig`, replaced: viewport, stylesheet, title and meta blocks
- [x] T013 `templates/public/layout.html.twig` — the site chrome every page and every error page extends
- [x] T014 [P] `templates/components/_article_card.html.twig`, `_pagination.html.twig`, `_tag_list.html.twig`
- [x] T015 `src/Controller/HomeController.php` and `templates/public/home.html.twig`
- [x] T016 `src/Controller/ArticleController.php` and `templates/public/article/show.html.twig`
- [x] T017 [P] `tests/Functional/HomeControllerTest.php` — the anonymous-reader case, ordering, the optional summary, every pagination boundary, and a query-count assertion for SC-007
- [x] T018 [P] `tests/Functional/ArticleControllerTest.php` — content, section and label links, the lead image with its alternative text, and an article that renders although its image file is not on disk

## Phase 4: US2 — unpublished work is invisible (P1)

- [x] T019 Write `tests/Functional/UnpublishedContentIsInvisibleTest.php` **first**, gathering every visibility assertion in one file. Fifteen tests: drafts and archived content unreachable by address for both content types, no redirect, nothing named on the 404 page, nothing in any listing or menu, an empty section rather than a disclosure, no draft ancestor in a breadcrumb, and — the one this file exists for — a draft address and a nonexistent address producing byte-identical responses
- [x] T020 Establish that these tests run with **debug off**. With debug on, Symfony answers a 404 with its own exception page, and two 404s differ because their debug output differs; the real question would go unasked
- [x] T021 Make the rule structural rather than checked: every public route resolves content through a repository method whose name contains `Published`, so there is no code path that can return a draft and no status check that can be forgotten

## Phase 5: US3 — sections and labels (P2)

- [x] T022 [P] `src/Controller/CategoryController.php` and `templates/public/category/show.html.twig`, including subsections
- [x] T023 [P] `src/Controller/TagController.php` and `templates/public/tag/show.html.twig`
- [x] T024 `tests/Functional/TaxonomyControllerTest.php` — both listings, ordering, pagination, an empty listing rendering as empty rather than as not-found, and a listing that does not exist returning not-found

## Phase 6: US4 — pages and the menu (P2)

- [x] T025 `src/Twig/MenuExtension.php` and `src/Twig/MenuRuntime.php` — one query per request, grouped in memory. A Twig function rather than a variable every controller passes down, because a menu missing from one page because a controller forgot it is a defect a reader finds first
- [x] T026 [P] `templates/components/_menu.html.twig`
- [x] T027 `src/Controller/PageController.php` — the root-level catch-all, its reserved first segments, and a breadcrumb that skips unpublished ancestors
- [x] T028 [P] `templates/public/page/show.html.twig`
- [x] T029 `tests/Functional/PageControllerTest.php` — the page itself, menu order, nesting, the catch-all not swallowing an article address, and a published page under a draft parent staying reachable
- [x] T030 Write `docs/adr/0008-public-address-scheme.md`, because reversing the scheme changes routes, templates and every address already published

## Phase 7: US5 — errors are pages (P2)

- [x] T031 [P] `templates/bundles/TwigBundle/Exception/error404.html.twig` — one response for three situations, with no branch that could tell them apart
- [x] T032 [P] `templates/bundles/TwigBundle/Exception/error.html.twig` — no variable that could carry a path, a class name or a trace
- [x] T033 `tests/Functional/ErrorPageTest.php` — the status, the site's own layout, a way back, and a list of strings that must not appear

## Phase 8: Polish

- [x] T034 Run `composer qa` and fix every finding at its cause. **379 tests, 780 assertions**, PHPStan level max, no baseline, no ignore comment, no skipped test
- [x] T035 Verify by hand against the development fixtures: every route, every visibility rule, the stylesheet actually served, and the pagination boundaries
- [x] T036 Update `docs/status.md`
- [ ] T037 Have the `symfony-reviewer` agent review the change — **not done**, for the same reason as feature 001's T077: this session cannot spawn subagents. Still required by the constitution before merge

## Four defects the tests found, all in the tests rather than the code

Worth listing, because a task list of nothing but ticks is not a useful record.

1. **The 404 tests were asserting against the debug exception page.** They passed
   a status check and failed a content check, which is how the debug-off decision
   in T020 came about. The tests were wrong; the code was right.
2. **The query-count test reported an N+1 in the wrong direction** — 8 queries for
   2 articles, 2 for 17. It was comparing a cold first request against a warm
   second one. Fixed with an unmeasured warm-up request.
3. **The reserved-prefix test asserted 404 for `/api`,** which API Platform
   legitimately answers with 200. That is exactly *why* `api` is reserved, so the
   assertion now checks the content rather than the status.
4. **A "links to its children once" assertion counted two links,** because the
   menu links to the same child. The selector was scoped to `main`; the second
   link was correct behaviour.

One expectation was wrong about the code rather than the other way round:
`?page=007` falls back to page 1, because the integer validator refuses leading
zeroes as ambiguous. The safe reading was kept and the test corrected.

## Not done in this feature, and stated so

- **FR-024 is not discharged.** Content markup renders as authored, which is what
  the requirement asks for, but nothing sanitises it. There is no editor yet, so
  the only author is a developer loading fixtures. The obligation passes to
  whichever feature first lets somebody paste markup in, and FR-024 is the
  number it has to satisfy.
- **Lead images point at files that are not there.** The catalogue records
  filenames; the upload feature that puts bytes on disk does not exist. FR-023
  exists for this and is tested — the article renders regardless.
- **No search, feeds, sitemap or social preview metadata.** Out of scope by the
  specification, and each is a reasonable next feature.
- **No caching of any kind**, including the one query per request the menu costs.
