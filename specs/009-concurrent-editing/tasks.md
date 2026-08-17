---

description: "Task list for feature 009 — concurrent editing"
---

# Tasks: Concurrent Editing

**Input**: Design documents from `/specs/009-concurrent-editing/`

**Written before the implementation.**

## The risk this feature actually carries

A version check is trivial to add and trivial to add uselessly.

There are three ways to end up with a check that passes every test and protects
nothing. The version can fail to advance when the record changes, in which case
no save is ever stale. The check can run after the changes have been applied to
the entity, in which case the refusal happens with the damage already staged. Or
the submitted version can be read from the entity rather than from the form, in
which case it always matches itself.

Every one of those is invisible from the outside unless a test opens *two* forms
before either is saved. So that is what the tests do, and the load-bearing
assertion is not "the second save was refused" but **"what the first editor wrote
is still stored"**.

The second risk is making ordinary editing worse. A screen that refuses a save
for no reason a person can see is worse than one that occasionally loses work,
because it happens every day. FR-011 exists for that, and it is tested by saving
the same form repeatedly and expecting nothing to happen.

---

## Phase 1: Setup

- [x] T001 Add a version to `src/Entity/PublishableContent.php`, so an article and a page get it once and cannot drift apart
- [x] T002 Generate the migration with `doctrine:migrations:diff` and check it defaults existing rows rather than rejecting them
- [x] T003 `src/Exception/ContentWasChangedElsewhere.php` — one class for one refused rule, carrying which version was submitted and which is stored (FR-012)

## Phase 2: US1 — the refusal

- [x] T004 [P] [US1] Write `tests/Functional/Admin/ConcurrentEditingTest.php` **first** — two forms opened before either is saved, then the second saved, then *what is stored* read back
- [x] T005 [US1] Carry the version on `ArticleCommand` and `PageCommand`, filled by `from()` (FR-002)
- [x] T006 [US1] Render it as a hidden field in `ArticleType` and `PageType` — the only field on those forms nobody types into
- [x] T007 [US1] Check it in `ArticleEditor::update()` and `PageEditor::update()`, **before** anything is applied (FR-003, FR-004)
- [x] T008 [US1] Leave `create()` alone (FR-009), and leave `PublicationService` and the delete routes alone (FR-010)

## Phase 3: US2 — what the second editor sees

- [x] T009 [P] [US2] Write the assertions for the returned page — the sentence, the submitted values still present, no success message, no error page
- [x] T010 [US2] Catch the refusal in `Admin/ArticleController::edit()` and `Admin/PageController::edit()` and re-render the form with the message (FR-005 to FR-007)

## Phase 4: US3 — the version cannot be forged

- [x] T011 [P] [US3] Write the cases: no version submitted, a version that was never real, a version from the future
- [x] T012 [US3] Make an absent version fail the check rather than pass it (FR-008)

## Phase 5: Polish

- [x] T013 [P] Update `docs/status.md` — the entry moves out of "known gaps", and taxonomy and media are recorded as still last-write-wins
- [x] T014 Run `composer qa`
- [x] T015 Verify by hand on the dev server with two browser sessions
- [ ] T016 `symfony-reviewer` pass — expected to remain open

## Notes

- The check belongs in the editor, not the controller. There are two controllers
  today and there will be more; the editor is the one path by which content is
  changed from a screen, which is what feature 004 built it for.
- The refusal answers **409 Conflict**, not 200. The submission genuinely was
  refused, and a status saying otherwise would be the same kind of lie as the
  "Saved." this feature removes. It is still a whole working screen.
- Doctrine refuses to map a version column whose type it was not told, so
  `#[ORM\Column(type: Types::INTEGER)]` is spelled out rather than inferred from
  the property — the diff command fails with a type error otherwise.
- One thing the test harness taught: a single browser standing in for two editors
  keeps the first one's "Saved." flash in the session if its redirect is never
  followed, and that flash then renders on the second editor's page. Following it
  is what a browser does anyway.
- No test here asserts on a version number directly. They assert on what is
  stored after two saves, because a version number is an implementation of the
  rule rather than the rule.
