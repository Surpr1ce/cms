---

description: "Task list for feature 004 — content administration"
---

# Tasks: Content Administration

**Input**: Design documents from `/specs/004-content-administration/`

## A deviation, reported again

**This list was written after the implementation.** Feature 003 got the order
right — spec, plan, tasks, then code — and this one slipped back to the habit
feature 002 had: specification and plan first, then straight to code, with the
task list assembled afterwards.

It is the second time, which makes it a pattern rather than an accident. Worth
saying plainly: the discipline holds under a small feature and gives way under a
large one, which is the opposite of when it is useful.

What did not slip is the order that mattered most inside the work. The sanitiser
and its hostile-input catalogue were written before anything that could store
content, and the permission tests before the screens they guard.

---

## Phase 1: The sanitiser, first

- [x] T001 Add `symfony/html-sanitizer` — the first runtime dependency since the skeleton
- [x] T002 Write `docs/adr/0010-sanitise-markup-on-the-way-in.md`: why not to write our own, why on the way in rather than out, and what that costs
- [x] T003 Write `tests/Unit/Service/Content/ContentSanitiserTest.php` **first** — 23 hostile inputs, 15 forms of legitimate markup, and the title rules
- [x] T004 Create `src/Service/Content/ContentSanitiser.php` — an allow-list, with `script`, `style`, `iframe`, `object`, `embed`, `form`, `base`, `meta` and `link` dropped contents and all
- [x] T005 Confirm sanitising is idempotent, so that "sanitise once, on the way in" cannot slowly erode an article across re-saves

## Phase 2: Foundational

- [x] T006 [P] `src/Form/Command/ArticleCommand.php` and `PageCommand.php` — plain data, so a form never writes to an entity that has no setters for status, address or author
- [x] T007 `src/Service/Content/ArticleEditor.php` and `PageEditor.php` — the one path by which content is stored, and therefore the one place sanitising and address generation happen
- [x] T008 `src/Service/Content/PublicationService.php` — the four transitions, adding no rule of its own
- [x] T009 [P] `src/Form/ArticleType.php` and `PageType.php`
- [x] T010 Add `src/Form/` to the `CLAUDE.md` architecture tree — the fourth such correction, after `Exception/`, `Factory/` and `Command/`

## Phase 3: US2 — sanitising through the screen (P1)

- [x] T011 Write `tests/Functional/Admin/SanitisingOnStoreTest.php` — submits through the real form and asserts on what was **read back from the database**, never on what a page displayed
- [x] T012 Prove the end-to-end case: hostile markup submitted, article published, and what a reader receives carries no attack
- [x] T013 Prove editing sanitises as well as creating — a second storage path that trusted what was already there would be a way in

## Phase 4: US1, US3, US4 — the screens

- [x] T014 Write `tests/Functional/Admin/AdminPermissionsTest.php` **before the screens** — every address anonymously, every refusal by direct submission rather than by an absent button
- [x] T015 `src/Controller/Admin/ArticleController.php`
- [x] T016 `src/Controller/Admin/PageController.php`
- [x] T017 [P] The admin layout, dashboard, and the article and page screens
- [x] T018 `src/Twig/AdminExtension.php` — `page_probe()`, so navigation asks the voter rather than checking a role name behind its back
- [x] T019 Write `tests/Functional/Admin/ArticleAdministrationTest.php` — creation, address generation, renaming, publishing, and a reader reading the result

## Phase 5: Polish

- [x] T020 [P] Update `docs/status.md`, retiring the inherited sanitising obligation and recording what remains
- [x] T021 Run `composer qa` — 597 tests, 1232 assertions
- [x] T022 Walk `quickstart.md` by hand
- [ ] T023 `symfony-reviewer` pass — still open, for the same reason as features 001 to 003

## Four defects the tests found

1. **The sidebar fields were outside the `<form>` element**, and the form closed
   with `render_rest: false`, which meant the CSRF token was never rendered.
   Every submission came back 422 with no visible message. Two silent bugs in
   one template, and the test that stores an article is what found them — a test
   that only loaded the screen would have seen a perfectly good-looking form.
2. **`setParameters()` needs an `ArrayCollection` in Doctrine ORM 3**, not an
   array. The page form's parent selector raised a 500 that only the
   permission test walked into.
3. **A title assertion was wrong about its own requirement.** It insisted the
   word `steal()` must not survive in a title. Stripping the tag leaves the words
   inside it, which is correct — a title is text everywhere it is rendered, so
   `steal()` in one is a peculiar choice of words and nothing more. The
   requirement is no *markup*, and that is what it now asserts.
4. **A CSRF token was fetched from the container**, which fails outside a
   request and would have tested a token the application never issued. Taking it
   from the rendered page is both simpler and closer to what happens.

One assertion compared timestamps that differed only in microseconds, because
the column is `TIMESTAMP(0)` and the object in memory was not. Compared to the
second now, which is the precision the data actually has.

## Not done in this feature

- **Screens for sections, labels, files and accounts.** Out of scope by the
  specification; they are the generic CRUD the conventions assign to EasyAdmin.
- **Uploading.** The lead-image picker offers what is already catalogued.
- **A rich-text editor.** The body is a text area containing markup, and
  sanitising deliberately does not depend on an editor being well behaved.
- **Optimistic locking.** Two people editing the same article: the second save
  wins, silently. Stated in the specification's Assumptions rather than
  discovered later.
