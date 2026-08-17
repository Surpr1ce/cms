---

description: "Task list for feature 007 — taxonomy and account administration"
---

# Tasks: Taxonomy and Account Administration

**Input**: Design documents from `/specs/007-taxonomy-and-accounts/`

**Written before the implementation.**

## The risk this feature actually carries

It is not disclosure, which is what features 002 to 006 spent their effort on. It
is that a generic tool quietly bypasses a rule the domain holds.

EasyAdmin's scaffolded delete calls `EntityManager::remove()`. For a section that
would leave the constraint to decide — `ON DELETE SET NULL`, so the articles
survive but the subsections become top-level rather than moving up to their
grandparent. For an account it would hit `ON DELETE RESTRICT` and produce a
foreign-key error where `UserDeleter` produces a sentence naming what is owned.

Neither is a leak, and both are a behaviour changing because of a tool rather
than because of a decision. FR-016 exists for exactly this, and the tests assert
the *outcome* — what survived, and what the person was told — rather than that a
particular method was called.

---

## Phase 1: Setup

- [x] T001 Create `src/Controller/Admin/EasyAdminDashboardController.php` — EasyAdmin needs a dashboard, and its menu is where the permission checks become visible
- [x] T002 Mount it under `/admin/manage`, so it cannot collide with the hand-written screens already at `/admin/articles`, `/admin/pages` and `/admin/media`
- [x] T003 Link to it from the existing administration navigation, behind the same voters

## Phase 2: US1 and US2 — sections and labels

- [x] T004 [P] Write `tests/Functional/Admin/TaxonomyAdministrationTest.php` **first** — create, rename, the address rules, and the two deletion rules that must survive the generic screen
- [x] T005 `src/Controller/Admin/CategoryCrudController.php` — name, description, parent. The address is generated, not typed
- [x] T006 `src/Controller/Admin/TagCrudController.php`
- [x] T007 Generate the address on creation, through `UniqueSlugGenerator`, and leave it alone on edit (FR-002, FR-003)
- [x] T008 Route deletion through `CategoryDeleter` rather than the scaffolded delete (FR-016)

## Phase 3: US3 — accounts

- [x] T009 [P] Write `tests/Functional/Admin/AccountAdministrationTest.php` **first** — creation, the blank-password rule, the refusals, and a check that no response carries a hash
- [x] T010 `src/Controller/Admin/UserCrudController.php` — email, display name, roles as checkboxes, password only to set one
- [x] T011 Hash on save; leave the stored credential alone when the field is blank (FR-008, FR-009)
- [x] T012 Route deletion through `UserDeleter`, so an account owning content is refused with a sentence rather than a constraint violation

## Phase 4: Permissions

- [x] T013 Every taxonomy screen behind `MANAGE_TAXONOMY`; every account screen behind `MANAGE_ACCOUNTS`
- [x] T014 Write the anonymous and insufficient-permission cases for every screen (FR-018)

## Phase 5: Polish

- [x] T015 [P] Update `docs/status.md` and `CLAUDE.md` if a directory is added
- [x] T016 Run `composer qa`
- [x] T017 Verify by hand: create a section, use it on an article, create an account, sign in as it
- [ ] T018 `symfony-reviewer` pass — expected to remain open

## Notes

- The deletion tests assert what survived, not which class was called. A test
  that checked for a service call would pass while the articles were destroyed.
- No screen exposes a slug field. An address is generated and then fixed, and a
  form offering to edit one would invite breaking every link to a section.
