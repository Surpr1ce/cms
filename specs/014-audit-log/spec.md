---

description: "Specification for feature 014 — a record of who did what"
---

# Feature Specification: Audit Log

**Feature Branch**: `014-audit-log`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The `docs/status.md` row "Audit log of who did what — not started". The last entry on that list that is a missing capability rather than a deliberate absence or an optimisation.

## Why this feature exists

Thirteen features have built a CMS in which several people can change things, and
none in which anybody can find out what they changed.

An article that was published and is now archived shows no sign of who archived
it or when. An account that holds an administrator's permissions today gives no
indication of who granted them. A file that used to be in an article and is now
gone leaves nothing behind at all.

This is not primarily about catching wrongdoing, and a specification that framed
it that way would build the wrong thing. It is about the ordinary questions a
site with more than one editor asks every week: *why did this disappear?*, *who
took this down and can we put it back?*, *did I do that?* Without an answer, the
only recourse is a database backup and a guess.

It is also the last thing standing between this CMS and being honest about
multiple editors. Feature 009 stopped two people overwriting each other silently.
This one stops the site as a whole changing silently.

**What this must not become.** A log of every field of every save is a log
nobody reads, and it doubles the size of the database to store the fact that
somebody fixed a typo. This records *decisions* — publishing, removing, granting
— not keystrokes.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An administrator can see what happened (Priority: P1)

They open a screen and read what was done, by whom, and when, newest first.

**Why this priority**: A record nobody can read is a table, not a feature.

**Independent Test**: Perform several recorded actions as different people, then
open the screen and read it.

**Acceptance Scenarios**:

1. **Given** actions have been taken, **When** an administrator opens the log,
   **Then** they see each one with who did it, what it was, and when
2. **Given** many entries, **When** the log is opened, **Then** they are newest
   first and paged like every other listing
3. **Given** an entry about something that still exists, **When** it is read,
   **Then** it names the thing in a way a person recognises
4. **Given** an entry about something that has been deleted, **When** it is read,
   **Then** it still names what it was — a log that goes blank when the subject
   goes is a log about nothing
5. **Given** somebody who is not an administrator, **When** they try to open the
   log, **Then** they are refused

### User Story 2 - The decisions that matter are all recorded (Priority: P1)

Publishing, removing and granting are recorded wherever they happen — not only
on the screen somebody remembered.

**Why this priority**: Equal to US1. A log with holes is worse than none,
because it invites the conclusion that nothing happened.

**Independent Test**: Perform each recorded action through every route that can
perform it, and check the log after each.

**Acceptance Scenarios**:

1. **Given** content is published, unpublished, archived or restored, **Then**
   the log records which and by whom
2. **Given** content is deleted, **Then** the log records it, including what it
   was called
3. **Given** an account is created, deleted, or has its permissions changed,
   **Then** the log records it
4. **Given** a file is deleted, **Then** the log records it
5. **Given** somebody's password is changed or reset, **Then** the log records
   that it happened — and **never** what it was changed to
6. **Given** an action taken by a console command rather than a screen, **Then**
   the log records it with no person attached rather than not at all

### User Story 3 - The record cannot be quietly edited (Priority: P2)

Nothing in the application can change or remove an entry.

**Why this priority**: Lower because it is a property rather than a capability,
and higher than it looks because a log that the application can rewrite is a log
that proves nothing.

**Independent Test**: Look for any route, form, service method or screen control
that alters an entry.

**Acceptance Scenarios**:

1. **Given** an entry, **When** anything in the application is used, **Then**
   there is no way to change it
2. **Given** an entry, **When** anything in the application is used, **Then**
   there is no way to delete it
3. **Given** an account that is deleted, **When** entries about its actions are
   read, **Then** they survive and still say who it was

### Edge Cases

- **Deleting an account must not delete its history**, and must not fail because
  of it. The record has to survive the person.
- **The name of a deleted thing** has to be in the entry itself; a reference to a
  row that no longer exists says nothing.
- **A password must never reach the log**, changed or otherwise.
- **A failed action is not an action.** A refused publish records nothing.
- **The log grows forever.** That is correct for a record and worth stating: it
  is not a cache and nothing expires it.
- **Recording must not be able to undo the thing it records.** If writing an
  entry fails, the publication still happened.

## Requirements *(mandatory)*

### Functional Requirements

**Recording**

- **FR-001**: The application MUST record each of: a publication transition, the
  deletion of content, the creation of an account, the deletion of an account, a
  change to an account's permissions, the deletion of a file, and a password
  being changed or reset
- **FR-002**: Each entry MUST carry who did it, what was done, what it was done
  to, and when
- **FR-003**: An entry MUST carry a description of its subject that remains
  meaningful after the subject is deleted
- **FR-004**: An action taken with no person attached — a console command — MUST
  be recorded as such rather than omitted
- **FR-005**: An entry MUST NOT contain a password, a hash, or a reset token
- **FR-006**: An action that was refused MUST NOT be recorded
- **FR-007**: Recording MUST NOT be able to prevent or undo the action it records

**Reading**

- **FR-008**: An administrator MUST be able to read the log, newest first, paged
- **FR-009**: Anybody who is not an administrator MUST be refused
- **FR-010**: The log MUST name the person who acted, including after that
  account is deleted

**Permanence**

- **FR-011**: The application MUST offer no way to change an entry
- **FR-012**: The application MUST offer no way to delete an entry
- **FR-013**: Deleting an account MUST NOT delete or break its entries

### Key Entities

- **AuditEntry** — when, what kind of action, a description of the subject, the
  account that acted (nullable, and severed rather than cascaded when that
  account goes), and the acting person's address recorded as text so it survives
  them.

## Success Criteria *(mandatory)*

- **SC-001**: "Who took this down?" has an answer that does not involve a backup
- **SC-002**: Every recorded action is recorded from every route that can perform
  it
- **SC-003**: Deleting an account leaves its history intact and readable
- **SC-004**: No entry anywhere contains a credential
- **SC-005**: Nothing in the application can alter the record
- **SC-006**: `composer qa` passes and the whole suite grows

## Assumptions

- **Decisions, not keystrokes.** Editing an article's body records nothing;
  publishing it records an entry. A log of every field of every save is a log
  nobody reads and a database twice the size.
- **Recorded in the services, not by Doctrine events.** A lifecycle listener
  would catch every write automatically and would know neither what the change
  *meant* nor who made it. The services are where a decision has a name.
- **The acting person is kept twice** — as a relation and as their address in
  text. The relation is severed when the account goes; the text is what makes the
  entry still say who it was.
- **Nothing expires.** A record that deletes itself after ninety days is a record
  that cannot answer a question asked on the ninety-first.
- **No screen filters it yet.** Newest first and paged is enough to be useful;
  filtering by person or by kind is a real improvement and its own work.
