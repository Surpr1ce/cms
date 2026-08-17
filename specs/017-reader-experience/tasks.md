---

description: "Task list for feature 017 — reader experience"
---

# Tasks: Reader Experience

**Input**: Design documents from `/specs/017-reader-experience/`

**Written after the report and before the fixes**, like features 015 and 016.
Three features in a row now whose entire content came from somebody opening the
running site.

## The risk this feature actually carries

Everything added here **recommends content to a reader**, and that is a new kind
of surface on a site that has spent sixteen features making sure unpublished work
is unreachable.

Every earlier read is safe structurally: the controller calls a repository method
that has no code path returning a draft. A "read next" written casually — order
by date, exclude this one, limit three — has no such property, and the failure is
quiet: an article that has not been announced appears in a list at the foot of a
published one, with its title, to anybody.

So both new queries go through `publishedQuery()` like everything else, and the
test that matters creates a draft and an archived article sharing a section *and*
a label with the one being read, and asserts neither is named anywhere in the
response.

The same rule caught a second thing. Pages already had a published-ancestors
list, built precisely so a trail cannot name a draft parent; the generic
breadcrumb component was first wired to `page_content.parent`, which would have
walked straight past that guarantee. It reads `ancestors` now.

---

## Phase 1: US1 — listings that show what the articles are

- [x] T001 `reading_time` filter on `SeoExtension`, never below one minute
- [x] T002 `components/_article_card.html.twig` — the lead image at `thumbnail`, and the reading time

## Phase 2: US2 — moving around

- [x] T003 `MenuRuntime::sections()` and a `public_sections()` function, top-level only
- [x] T004 `components/_breadcrumbs.html.twig`, taking a trail and rendering the last item as text
- [x] T005 Trails on the article, section, label, page and search screens
- [x] T006 Rebuild `public/layout.html.twig` — sections in the navigation, a footer with the feed, the sections, the pages and the way in, and a skip link

## Phase 3: US3 — an article that leads somewhere

- [x] T007 [P] Write `tests/Functional/ReaderExperienceTest.php` **first**, including the assertion that nothing unpublished is ever suggested
- [x] T008 `ArticleRepository::findPublishedRelatedTo()` — same section or shared labels, most shared first
- [x] T009 `ArticleRepository::findPublishedNeighboursOf()` — the published articles either side by date
- [x] T010 `components/_read_next.html.twig` and the controller passing all three

## Phase 4: Polish

- [x] T011 A result count on the search page
- [x] T012 Update `docs/status.md`
- [x] T013 Run `composer qa`
- [x] T014 Walk the running site again
- [ ] T015 `symfony-reviewer` pass — expected to remain open

## What the work found

- **A `GROUP BY` cannot join-fetch.** Counting shared labels needs one, and
  PostgreSQL refuses to select every column of a joined author and section while
  grouping by the article. The related query fetches nothing extra, which is
  correct anyway — the list it feeds shows a title, a date and a reading time.
- **The page template already had a breadcrumb**, built from published ancestors
  rather than from `.parent`. Adding a generic one gave that screen two, and the
  generic one was the less careful of the two. The shared component now draws the
  ancestors list.
- **A top-level page had no trail at all.** It has one now — the site and the
  page — because being the only content page without one is worse than the
  redundancy, and because the trail is also the link back to the front page.
- **The stale non-debug test container bit for the third time**, this time as
  "too few arguments to `MenuRuntime::__construct()`". `tests/bootstrap.php` now
  deletes it before the suite runs. It has looked like three completely different
  faults across three features; one container build per run is worth never
  debugging it again.

## Notes

- Related articles are "same section or shared labels", ordered by how much is
  shared. Anything cleverer is a recommendation engine, and a CMS that guesses is
  a CMS that guesses wrong in public.
- No author pages. Crediting an author with a name that links nowhere is a real
  gap, and giving accounts public addresses is a decision with its own privacy
  question — recorded in `docs/status.md` rather than done quietly here.
