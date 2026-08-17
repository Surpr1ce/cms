---

description: "Task list for feature 010 — discoverability"
---

# Tasks: Discoverability

**Input**: Design documents from `/specs/010-discoverability/`

**Written before the implementation.**

## The risk this feature actually carries

Three deliveries of the same content, and every one of them is a chance to leak
it.

Feature 002 proved that a draft is unreachable on the website, and feature 006
proved the API agrees. This feature adds three more front doors, and the ways
they go wrong are all the same way: a query written for a sitemap that fetches
everything and filters in a template; a feed built from `findAll()` and trimmed
to twenty; a preview description read straight from a body without asking whether
the body is visible.

So nothing here writes a query. Every one of these reads through the same
repository methods the website's controllers already use — the ones that
structurally cannot return unpublished content — and the tests assert it the way
feature 002 did: create a draft, then look for its title in what comes out.

The second risk is a sitemap that lies. A sitemap listing an address that answers
404 is worse than no sitemap, because it teaches a crawler to distrust the whole
document. The test for that is not a comparison of lists; it is **requesting
every address the sitemap contains**.

---

## Phase 1: Setup

- [x] T001 `src/Service/Seo/PlainText.php` — turn a body into one short line: markup gone, whitespace collapsed, cut on a word boundary
- [x] T002 [P] Write `tests/Unit/Service/Seo/PlainTextTest.php` — markup, entities, newlines, an empty body, a body shorter than the limit, a cut that must not fall mid-word

## Phase 2: US1 — the sitemap

- [x] T003 [P] [US1] Write `tests/Functional/SitemapTest.php` **first** — every published address present, nothing unpublished, and **every address in it requested and answered**
- [x] T004 [US1] `src/Controller/SitemapController.php` at `/sitemap.xml`, reading through the published repository methods only
- [x] T005 [US1] `templates/public/sitemap.xml.twig` — absolute addresses, a change date per entry
- [x] T006 [US1] `/robots.txt`, naming the sitemap and excluding `/admin`

## Phase 3: US2 — the feed

- [x] T007 [P] [US2] Write `tests/Functional/FeedTest.php` **first** — order, exclusion of the unpublished, a parseable document, and a body full of hostile markup that must not break it
- [x] T008 [US2] `src/Controller/FeedController.php` at `/feed.xml`, twenty most recent published articles
- [x] T009 [US2] `templates/public/feed.xml.twig` — Atom, absolute addresses, unambiguous dates
- [x] T010 [US2] Advertise it from every page (FR-011) — done in `base.html.twig` rather than the public layout, so the administration screens carry it too and no future layout can omit it

## Phase 4: US3 — preview metadata

- [x] T011 [P] [US3] Write `tests/Functional/PreviewMetadataTest.php` **first** — the tags on an article, on a page, on a listing; the image present and absent; the description free of markup
- [x] T012 [US3] Built into `base.html.twig` rather than as a component filled by each template. Twig's `block()` reads the title and description a page already declares, so a template gains a working preview by doing nothing — and FR-012 says *every* page, which is not a thing to maintain by hand across a dozen templates
- [x] T013 [US3] Give the listing templates a description of their own, and add the canonical address (FR-015)

## Phase 5: Polish

- [x] T014 [P] Update `docs/status.md`
- [x] T015 Run `composer qa`
- [x] T016 Verify by hand: fetch the sitemap and the feed from the dev server and check both parse
- [ ] T017 `symfony-reviewer` pass — expected to remain open

## Notes

- No new repository methods unless a published-only one is missing. Adding a
  method that returns everything, for a sitemap to filter, is the exact mistake
  this feature is most likely to make.
## What the tests found

- **`Request::query->getInt()` throws** rather than answering when the value is
  not a number, and the suite already had a test requesting `?page=abc`. The
  canonical address now goes through `Paginator::pageNumberFrom()`, which is the
  one place that decides what such a request means — a canonical address
  disagreeing with the page that was actually rendered would be worse than none.
- **A Twig block cannot be declared on its own line and also captured.** Writing
  `{% block preview_image %}{% endblock %}` to declare it prints it as well, so
  an image address ends up as text in the middle of the head.
  `{% set x %}{% block x %}{% endblock %}{% endset %}` does both in one.
- **The front page had no description** and therefore no preview at all, which is
  what a test walking *every* kind of page is for. The listing templates now
  declare one, and the base layout falls back to the site's name so a template
  added later cannot reintroduce the gap.

## Notes, continued

- The site's address comes from the request. Nothing here may need a
  configuration value somebody has to remember to change when the site moves.
