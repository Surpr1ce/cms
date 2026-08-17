# Feature 019 — tasks

## Phase 1: Foundational

- [x] T001 `ArticleRepository::findPageForViewer()` in
      `src/Repository/ArticleRepository.php`, expressing `ArticleVoter::canView()`
      as a query
- [x] T002 `findPage()` on `PageRepository`, `MediaRepository`, `UserRepository`
      and `TagRepository`

## Phase 2: US2 — the article list agrees with the voter

- [x] T003 [US2] `Admin\ArticleController::index()` reads a page rather than the
      table, with no per-row voter call
- [x] T004 [US2] Test in
      `tests/Integration/Repository/ArticleVisibilityMatchesTheVoterTest.php`
      comparing the query against the voter for every role combination

## Phase 3: US1 — the rest of the listings

- [x] T005 [US1] Paginate `Admin\PageController::index()`
- [x] T006 [US1] Paginate `Admin\MediaController::index()`, replacing the silent
      cap of 100
- [x] T007 [US1] Paginate `Admin\AccountController::index()`
- [x] T008 [US1] Paginate `Admin\LabelController::index()`
- [x] T009 [US1] Record why `Admin\SectionController::index()` is not paginated
- [x] T010 [US1] Add the pagination component to each of those templates
- [x] T011 [US1] Test in `tests/Functional/Admin/ListingsArePaginatedTest.php`:
      a full first page offers a next one, the second holds the rest, and the
      query count does not grow with the page

## Phase 4: US3 — the sitemap

- [x] T012 [US3] Cap `SitemapController` at the protocol's 50,000 addresses
- [x] T013 [US3] Test that the cap holds and is not silent

## Phase 5: Polish

- [x] T014 `composer qa` green
- [x] T015 `node tools/browser-check.mjs` green
- [x] T016 Update `docs/status.md`

## What the work added beyond the plan

Recorded because a task list that hides what was actually done is worse than one
that admits it grew.

- **Two N+1s, found by T011's query-count assertion rather than by reading.** The
  files screen names each uploader and the pages screen shows each parent, and
  both associations were lazy — twenty rows, twenty-one queries. `findPage()` on
  `MediaRepository` and `PageRepository` now fetches the association it knows the
  screen displays.
- **`App\Service\Sitemap\SitemapBudget`.** T012 said "cap the controller", but a
  cap per list is four times the cap, and the arithmetic of one budget spent
  across four lists is worth testing without fifty thousand rows in a database.
  The controller keeps only the order the lists are spent in.
- **An optional limit on `CategoryRepository::findAllOrdered()` and
  `TagRepository::findInUse()`.** The sitemap is the one caller with a ceiling;
  a menu builder and a tag cloud want all of them, so the limit is optional
  rather than imposed on every caller.
- **`tools/browser-check.mjs` waits for a page to be ready** instead of sleeping
  for six seconds. Against the built-in PHP server, which handles one request at
  a time, the fixed wait expired while the module graph was still loading and the
  tool reported five failures in code that was fine — the exact false report its
  own header warns against.
- **Two stale rows in `docs/status.md`**: search rate limiting was listed as not
  implemented after feature 018 implemented it, and the sitemap's ceiling was
  still described as ten thousand.
