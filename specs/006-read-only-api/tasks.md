---

description: "Task list for feature 006 — read-only JSON API"
---

# Tasks: Read-only JSON API

**Input**: Design documents from `/specs/006-read-only-api/`

**Written before the implementation.**

## The shape of the decision

API Platform can map an entity directly and generate everything from it. That is
its selling point, and it is the wrong tool for this particular job, for two
reasons that both come from the specification:

- **A mapped entity exposes its fields.** `User` would leak an email address, a
  role list and a password hash unless every one is excluded by hand, and the day
  somebody adds a field is the day it appears in the API. FR-013 asks for the
  opposite default.
- **A mapped entity is addressed by its identifier and, by default, writable.**
  FR-010 and FR-014 both say no.

So the API exposes **its own read models** — plain objects that say exactly what a
consumer gets — fed by **state providers** that call the same repository methods
the website's controllers call. That is what makes FR-009 true by construction
rather than by discipline: there is no query here to get wrong.

---

## Phase 1: Setup

- [x] T001 Configure `config/packages/api_platform.yaml` — a real title and description, and defaults that keep resources read-only
- [x] T002 Decide and record the addressing scheme: slugs, not identifiers

## Phase 2: The read models

- [x] T003 [P] `src/ApiResource/ArticleResource.php` — title, slug, summary, body, published date, author display name, section, labels, lead image
- [x] T004 [P] `src/ApiResource/PageResource.php`
- [x] T005 [P] `src/ApiResource/CategoryResource.php` and `TagResource.php`
- [x] T006 Each declares only `GetCollection` and `Get`. No `Post`, `Put`, `Patch` or `Delete`, anywhere

## Phase 3: The providers

- [x] T007 `src/State/ArticleProvider.php` — calls `ArticleRepository::findPublishedPage()` and `findOnePublishedBySlugWithRelations()`, the same methods the website uses
- [x] T008 [P] `src/State/PageProvider.php`
- [x] T009 [P] `src/State/CategoryProvider.php` and `TagProvider.php`
- [x] T010 Confirm no provider contains a status comparison of its own (FR-009, SC-005)

## Phase 4: US2 and US4 — the two rules that matter

- [x] T011 Write `tests/Functional/Api/ApiExposesOnlyPublishedContentTest.php` — every collection, every address, drafts and archived content, and a comparison against what the website returns
- [x] T012 Write `tests/Functional/Api/ApiIsReadOnlyTest.php` — POST, PUT, PATCH and DELETE against every collection and item address, and a check that nothing changed
- [x] T013 Write a test asserting no response contains an email address, a role or a hash (FR-018)

## Phase 5: US1 and US3 — the content itself

- [x] T014 + T015 `tests/Functional/Api/ApiContentTest.php` — **one file, not two.** The plan asked for `ArticleApiTest` and `TaxonomyApiTest`; sections and labels turned out to be three assertions each, and a file per resource would have separated "what a listing carries" from "what it links to" for no gain. Covers the collection, an item, ordering, pagination, the lead-image address, page placement, sections, and the entry point

## Phase 6: Polish

- [x] T016 [P] Update `docs/status.md`
- [x] T017 Run `composer qa`
- [x] T018 Verify by hand against the running site: the collection, an item with its image address, and all four write methods refused
- [ ] T019 `symfony-reviewer` pass — expected to remain open

## Notes

- SC-004 is the interesting test: ask the API and the website what is published,
  and compare. It is the assertion ADR 2's whole architecture exists to make
  true, and until now nothing has checked it.
- Every write test asserts the content afterwards as well as the status code. A
  405 with the change applied would be the worst possible outcome and the easiest
  to miss.
