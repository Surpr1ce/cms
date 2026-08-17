---

description: "Task list for feature 014 — audit log"
---

# Tasks: Audit Log

**Input**: Design documents from `/specs/014-audit-log/`

**Written before the implementation.**

## The risk this feature actually carries

A log is easy to write and easy to write uselessly. Three ways, and the tests are
shaped around all three.

**It goes blank at the moment it is needed.** Somebody reaches for this log
because something has gone — an article, a file, a colleague. If an entry is a
reference to a row, the entry says nothing the moment the row is deleted, and the
one question anybody ever asks of a log has no answer. So the subject is text and
the actor is kept twice, and the tests delete both the subject and the actor and
then read the log.

**It has holes.** A log missing one route is worse than no log, because it
invites the conclusion that nothing happened. Every recorded action is tested
through the screen that performs it.

**It records too much.** A log of every save is a log nobody reads, and this one
would then be a slower database and a false sense of oversight. Editing an
article records nothing, deliberately, and that is asserted rather than assumed.

The fourth risk is the quietest: **recording something must never be able to undo
it.** If writing an entry fails, the article stays published. That is a `try`
around the write and a line in the application log, not an exception the person
who published sees.

---

## Phase 1: Setup

- [x] T001 `src/Entity/AuditAction.php` — a closed, short list of the decisions worth recording
- [x] T002 `src/Entity/AuditEntry.php` — no setters, a text subject, the actor kept twice
- [x] T003 `src/Repository/AuditEntryRepository.php` — reading only, and a left join so entries outlive their actors
- [x] T004 `src/Service/Audit/AuditLog.php` — writes, reads the actor from the session, and cannot undo what it records
- [x] T005 Migration, generated with `doctrine:migrations:diff`

## Phase 2: US2 — recording the decisions

- [x] T006 [P] [US2] Write `tests/Functional/Admin/AuditLogTest.php` **first** — every transition, deletion, account change and password change, and a refused action recording nothing
- [x] T007 [US2] `PublicationService` — all four transitions, recorded after the flush so a refusal records nothing
- [x] T008 [US2] Content deletion in `Admin/ArticleController` and `Admin/PageController`, reading the title before the row goes
- [x] T009 [US2] `MediaDeleter` and `UserDeleter`, the same way
- [x] T010 [US2] `UserCrudController` — account created, and permissions changed **only when they actually changed**
- [x] T011 [US2] `PasswordResetService` — both the reset and the deliberate change, recording that the credential moved and never what to

## Phase 3: US1 — reading it

- [x] T012 [US1] `src/Controller/Admin/AuditLogController.php` at `/admin/log`, behind `MANAGE_ACCOUNTS`
- [x] T013 [US1] `templates/admin/log/index.html.twig`, newest first and paged
- [x] T014 [US1] A link in the administration navigation, behind the same capability

## Phase 4: US3 — permanence

- [x] T015 [P] [US3] Assert that no route under `/admin/log` accepts anything but `GET`
- [x] T016 [US3] Assert that deleting an account leaves its entries readable and attributed

## Phase 5: Polish

- [x] T017 [P] Update `docs/status.md`
- [x] T018 Run `composer qa`
- [x] T019 Verify by hand on the dev server
- [ ] T020 `symfony-reviewer` pass — expected to remain open

## Notes

- Recorded in the services, not by a Doctrine lifecycle listener. A listener
  would catch every write automatically, which sounds better and is worse: it
  would know neither what a change *meant* nor who made it, and it would record a
  typo correction with the same weight as somebody being granted an
  administrator's permissions.
- The permissions entry compares before and after, sorted, so re-saving an
  account without touching its roles records nothing. Order is not meaning.
- Reading the log is behind `MANAGE_ACCOUNTS` rather than a role name: reading
  who did what is the same kind of authority as deciding who may do it, and an
  editor with this screen would have a surveillance tool nobody granted them.
- Nothing expires. A record that deletes itself after ninety days cannot answer a
  question asked on the ninety-first.

## What the tests found

- **Two accounts with the same address.** The refusal test rebooted the kernel
  between roles and expected the previous account to be gone with it. It is not:
  the suite runs inside one transaction, and only a rollback removes anything.
  Distinct addresses, and a sign-out between.
