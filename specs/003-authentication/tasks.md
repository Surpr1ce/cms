---

description: "Task list for feature 003 — authentication and authorisation"
---

# Tasks: Authentication and Authorisation

**Input**: Design documents from `/specs/003-authentication/`

**Written before the implementation**, correcting the departure reported in
feature 002's task list.

**Tests**: not optional. FR-025 and FR-026 make them part of the feature, and
constitution principle IV — every route gets an anonymous case and a wrong-role
case — finally has something to bind to.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup

- [x] T001 Add `src/Command/` to the architecture tree in `CLAUDE.md`, with a line on what belongs there. Same correction made for `Exception/` and `Factory/` in feature 001 — a directory the conventions do not mention is drift, however obvious it looks
- [x] T002 Write `docs/adr/0009-voters-instead-of-role-hierarchy.md`: why every grant is explicit code rather than a YAML hierarchy, and why the first administrator is created from the console rather than seeded

## Phase 2: Foundational

- [x] T003 Rewrite `config/packages/security.yaml` — an entity provider over `User`, a `main` firewall with form login and logout, and `access_control` closing `/admin`. Delete the `users_in_memory` provider the skeleton shipped
- [x] T004 Create `src/Controller/SecurityController.php` — `/login` rendering the form and reading the authentication error, `/logout` with an empty body because the firewall intercepts it
- [x] T005 [P] Create `templates/public/security/login.html.twig` inside the site's own layout, with a CSRF token field
- [x] T006 Add a throwaway `/admin` route so the gate can be tested before feature 004 exists, and mark clearly in its docblock that feature 004 replaces it
- [x] T007 Give `UserFactory` a `withPassword()` state that hashes a known password, so functional tests can sign in

## Phase 3: US1 — signing in and out (P1)

### Tests first

- [x] T008 [P] [US1] `tests/Functional/LoginTest.php` — the form renders anonymously and in the site layout; correct credentials sign in; a wrong password is refused; an unknown email address is refused **with the same message**; an account with an empty credential cannot sign in whatever is submitted; a submission without a CSRF token is refused; sign-out ends recognition; a signed-in person is sent away from the form

### Implementation

- [x] T009 [US1] Wire form login against the entity provider and confirm the refusal message is one message, not two (FR-002, SC-002)
- [x] T010 [US1] Confirm no password reaches a log or a response under any input (FR-003, SC-004)

## Phase 4: US2 — the administration area is closed (P1)

### Tests first

- [x] T011 [P] [US2] `tests/Functional/AdministrationIsClosedTest.php` — an anonymous request redirects to the form and discloses nothing; a signed-in person without the role is refused rather than redirected; the redirect returns them to where they were going; the public site is unaffected

### Implementation

- [x] T012 [US2] `access_control` over `^/admin`, and a check that no public route regressed — the existing 73 functional tests must pass unchanged (SC-006)

## Phase 5: US3, US4, US5 — the permission matrix (P1/P2)

### Tests first

- [x] T013 [P] [US3] `tests/Unit/Security/ArticleVoterTest.php` — every role against their own and somebody else's article, in every state, for view, edit, delete and publish. The refusals matter more than the grants: a voter that returns true for everything passes every happy-path test ever written
- [x] T014 [P] [US4] `tests/Unit/Security/PageVoterTest.php` — role only, because a page has no author for ownership to refer to (FR-022)
- [x] T015 [P] [US5] `tests/Unit/Security/AdministrationVoterTest.php` — taxonomy, files and accounts per role; an editor refused accounts; an administrator refused their own deletion (FR-020)

### Implementation

- [x] T016 [US3] `src/Security/ArticleVoter.php` — ownership plus role. An author edits their own drafts; publication is never theirs; a published article stops being theirs alone
- [x] T017 [P] [US4] `src/Security/PageVoter.php`
- [x] T018 [P] [US5] `src/Security/AdministrationVoter.php`
- [x] T019 Confirm an account with no roles, or an unrecognised role, is granted nothing (FR-021), and that a permission question about a nonexistent subject is refused rather than raising (FR-023)

## Phase 6: bootstrap

- [x] T020 [P] `src/Command/CreateAdministratorCommand.php` — create or promote an account and set its password, interactively or from arguments
- [x] T021 [P] `tests/Integration/Command/CreateAdministratorCommandTest.php` — a new account is created with the administrator role and a working hash; an existing address is promoted rather than duplicated; a weak or empty password is refused

## Phase 7: Polish

- [x] T022 [P] Update `src/Story/AppStory.php` so the fixture accounts have usable passwords, and document them in `docs/setup.md` as development-only
- [x] T023 [P] Update `docs/status.md`, including that **rate limiting on the sign-in form is not implemented** — a reader could otherwise assume it from "the door is locked"
- [x] T024 Run `composer qa` and fix every finding at its cause
- [x] T025 Walk `quickstart.md` by hand
- [ ] T026 `symfony-reviewer` pass — expected to remain open for the same reason as features 001 and 002

## Two defects found by writing the tests first

Both are the reason the order matters, and neither would have been found by
testing the happy path.

1. **The article voter granted permission on ownership alone.** An account whose
   author role had been revoked would have kept every permission over everything
   it had ever written. The permission matrix caught it on its first run,
   because it enumerates rather than samples: the row "an account with no roles
   editing its own draft" is not one anybody writes by hand.
2. **Two tests of role revocation passed while proving nothing.** They modified
   an account object belonging to an entity manager the kernel reboot had already
   discarded, so the flush wrote nothing and the assertion held for the wrong
   reason. This nearly caused FR-024 to be weakened on the grounds that the
   behaviour could not be demonstrated — it can; the tests were wrong. Both the
   requirement and the test now carry the story.

## Notes

- Every voter test asserts refusals as well as grants. A voter that grants
  everything is indistinguishable from a correct one under happy-path testing,
  and authorisation is the one place where that failure is silent and total.
- `/admin` has no real routes until feature 004. The gate is configured and
  tested over a throwaway route, because a lock installed after the door is a
  lock that was never tested against a real one.
