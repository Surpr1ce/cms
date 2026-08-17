---

description: "Task list for feature 011 — search"
---

# Tasks: Search

**Input**: Design documents from `/specs/011-search/`

**Written before the implementation.**

## The risk this feature actually carries

A search box is an oracle, and this is the first delivery where the visibility
rule is genuinely hard to keep.

Every earlier one reads through a repository method that cannot return
unpublished content — `findPublishedPage()`, `findOnePublishedBySlug()`. A search
cannot reuse those, because it needs a `WHERE` clause of its own, and the moment
somebody writes a new query the structural guarantee is gone and replaced by a
line of SQL that has to be right.

Getting it wrong is not a leaked article. It is worse and quieter: a reader who
searches for *acquisition*, gets "1 result" and a title, and now knows something
unannounced exists. Or the same reader gets an empty page for one word and a
"nothing matched" for another, and can distinguish them.

So FR-004 asks for something stronger than "drafts are excluded": the response
for a word only a draft contains must be **identical** to the response for a word
nothing contains at all. That is what the test asserts, and it is the assertion
that would catch a count leaking, a total leaking, or a paging control appearing.

The second risk is the query reaching the database as anything but a value. Full
text search takes a query expression, and building one by concatenation is
injection with extra steps. `plainto_tsquery` exists precisely so that a reader's
words are words.

---

## Phase 1: Setup

- [x] T001 A migration adding GIN indexes over the searchable text of `article` and `page`
- [x] T002 Confirm `doctrine:migrations:diff` does not try to drop them afterwards — it does not. Doctrine describes tables and columns and does not see an expression index at all, so a later diff reports "no changes" and leaves them alone. Nothing was needed

## Phase 2: US1 and US2 — finding, and not finding

- [x] T003 [P] [US1] Write `tests/Functional/SearchTest.php` **first** — a body match, a title outranking a body, both kinds of content, nothing matched, paging
- [x] T004 [P] [US2] Write the invisibility cases in the same file — a draft, an archived article, a draft page, and the **identical-response** assertion FR-004 asks for
- [x] T005 [US1] **Done differently.** One `UNION` in `src/Search/SiteSearch.php` rather than a method on each repository: merging two separately ranked lists is wrong the moment paging is involved, because page two of a merged list is not page two of either half
- [x] T006 [US1] `src/Search/SiteSearch.php` — one query, both kinds ranked against each other, paged. A new `src/Search/` namespace, recorded in `CLAUDE.md`
- [x] T007 [US1] `src/Controller/SearchController.php` at `/search`
- [x] T008 [US1] `templates/public/search.html.twig` — results, the empty-query invitation, the nothing-matched message

## Phase 3: US3 — the box and its edges

- [x] T009 [P] [US3] Write the edge cases — an empty query, one character, two hundred characters, a query made of markup, a query of punctuation
- [x] T010 [US3] `src/Search/SearchQuery.php` — normalise, bound the length, decide whether it is worth running at all
- [x] T011 [US3] A search box in the site header, on every page
- [x] T012 [US3] `noindex` on the results page (FR-013)

## Phase 4: Polish

- [x] T013 [P] Update `docs/status.md`
- [x] T014 Run `composer qa`
- [x] T015 Verify by hand on the dev server against the fixture content
- [ ] T016 `symfony-reviewer` and `security-auditor` passes — expected to remain open

## Notes

- The reader's words go to the database as a bound parameter and are turned into
  a query by `plainto_tsquery`, which treats operators as words. Nothing builds a
  query expression by concatenation.
- Ranking weights the title above the body, which is what makes a search for a
  headline find the headline rather than the twelve articles that mention it.
## What the tests found

- **A regression in feature 010, not in this feature.** The identical-response
  assertion caught that a 404 for a draft and a 404 for an address that never
  existed had stopped matching: the preview and canonical tags name the address
  that was asked for. Nothing was disclosed — a reader is only shown the address
  they typed — but a proxy with exceptions protects nothing. The error templates
  now carry `noindex` and no preview metadata, which is what a 404 deserves
  anyway.
- Everything else passed first time, which is worth recording honestly rather
  than treated as evidence the tests are weak: the invisibility rule is one
  `WHERE` clause per half and it was written with the test already in front of
  it.

## Notes, continued

- The search is public and unauthenticated and does the same thing for everybody.
  There is no "search as an editor"; the administration listings are a different
  screen and a different feature.
