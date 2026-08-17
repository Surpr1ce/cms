---

description: "Specification for feature 009 — two people editing the same thing"
---

# Feature Specification: Concurrent Editing

**Feature Branch**: `009-concurrent-editing`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The last entry under "Known gaps in what *is* built" that describes work being destroyed rather than merely absent — "Two people editing the same article: the second save wins, silently."

## Why this feature exists

Everything else on the "not done" list is a feature nobody has yet. This one is
different: the editing screens work, they look correct, and they quietly throw
away somebody's afternoon.

Two editors open the same article. The first saves. The second saves ten seconds
later, from a form filled before the first save existed, and every word the first
editor wrote is gone. Nobody is told. There is no copy, no history and no
warning; the second editor sees "Saved." and the first discovers the loss
whenever they next look.

That is worse than a missing feature, because a missing feature announces itself.
The constitution's line about reporting honestly applies to the software as much
as to the reports about it: a screen that says "Saved." must not mean "saved, and
destroyed something you were not shown".

The whole feature is one question asked at the right moment — *is this still the
version you were editing?* — and one honest answer when it is not.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A stale save is refused rather than applied (Priority: P1)

Two editors open the same article. The first saves. The second saves. The second
is told that somebody else changed it, and nothing is overwritten.

**Why this priority**: It is the entire feature. Everything else here is about
what the second editor is told afterwards.

**Independent Test**: Load the edit form twice, save through the first, save
through the second, then read what is stored.

**Acceptance Scenarios**:

1. **Given** two forms opened on the same article, **When** the first is saved,
   **Then** it saves normally
2. **Given** the same two forms, **When** the second is saved afterwards,
   **Then** it is refused and what the first editor wrote is still stored
3. **Given** an editor who opens a form and saves it with nobody else involved,
   **When** they save repeatedly, **Then** every save succeeds — a version check
   must not make ordinary editing fail
4. **Given** a refused save, **When** the editor reloads and saves again,
   **Then** it succeeds, because they are now working from what is current

### User Story 2 - The refusal is legible and loses nothing typed (Priority: P1)

The second editor is told what happened, in a sentence, and does not lose the
words they typed.

**Why this priority**: A refusal that discards the typing swaps one form of data
loss for another. This has the same priority as US1 deliberately.

**Independent Test**: Cause a conflict, then read the page that comes back —
looking for both an explanation and the submitted text.

**Acceptance Scenarios**:

1. **Given** a refused save, **When** the page comes back, **Then** it explains
   that somebody else changed the content since the form was opened
2. **Given** a refused save, **When** the page comes back, **Then** the text the
   editor typed is still in the form
3. **Given** a refused save, **When** the page comes back, **Then** it does not
   claim the content was saved
4. **Given** a refused save, **Then** the response is not an error page and not a
   500 — the form is a working screen with a message on it

### User Story 3 - The rule cannot be bypassed by the form (Priority: P2)

A submission that carries no version, or a version somebody edited, is refused
rather than trusted.

**Why this priority**: Lower because it is not what a colleague does by accident.
Real, because the version travels through the browser and anything that does is
under somebody else's control.

**Independent Test**: Submit the form with the version field removed, and again
with it set to a value that was never real.

**Acceptance Scenarios**:

1. **Given** a submission with no version at all, **When** it is saved, **Then**
   it is refused rather than treated as current
2. **Given** a submission carrying a version that is not the stored one, **When**
   it is saved, **Then** it is refused
3. **Given** a submission carrying a version from the future, **When** it is
   saved, **Then** it is refused

### Edge Cases

- **Creating is not editing.** A new article has no version to conflict with, and
  the check must not appear on the creation screen at all.
- **Publishing is not editing.** A publication change writes a status, not a
  body, and refusing to publish because somebody fixed a typo would be a rule
  nobody asked for. Out of scope, and stated so rather than left ambiguous.
- **Deleting is not editing** either, and the same reasoning applies.
- **The version must move when the content moves.** If saving a title does not
  advance it, the second save is not stale and the whole check is decoration.
- **Two saves that do not overlap must both succeed.** An editor who saves,
  reloads and saves again is not in conflict with themselves.
- **The refusal must not half-apply.** Nothing may reach storage before the check
  refuses — a conflict that saved the title and then refused the body would be
  worse than no check.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Articles and pages MUST each carry a version that advances every
  time the stored record changes
- **FR-002**: An edit form MUST carry the version of the content it was opened on
- **FR-003**: Saving MUST compare the submitted version against the stored one
  and refuse when they differ
- **FR-004**: A refused save MUST leave storage exactly as it was — no field of
  it applied
- **FR-005**: A refused save MUST return the editing screen with the submitted
  values still in it
- **FR-006**: A refused save MUST explain that somebody else changed the content,
  and MUST NOT report success
- **FR-007**: A refused save MUST NOT produce an error page or a server error
- **FR-008**: A submission with a missing or unparseable version MUST be refused,
  not treated as current
- **FR-009**: The check MUST NOT apply to creating content, which has no earlier
  version
- **FR-010**: The check MUST NOT apply to publication transitions or to deletion
- **FR-011**: Ordinary editing by one person MUST be unaffected — no extra step,
  no visible field, and no refusal
- **FR-012**: The refusal MUST be expressed as a domain rule, so that a test can
  assert on the rule that was broken rather than on a message
- **FR-013**: The version MUST NOT be editable by anybody through any screen

### Key Entities

- **Article** and **Page** each gain a version. It is not content, it is not
  shown to a reader, and it appears in no listing, no feed and no API response —
  it exists solely to answer "is this still the record you had".

## Success Criteria *(mandatory)*

- **SC-001**: An editor's work can no longer be destroyed by another editor
  saving after them
- **SC-002**: The second editor learns this immediately, in a sentence, on the
  screen they were already on
- **SC-003**: Nothing typed is lost when a save is refused
- **SC-004**: One person editing alone notices no difference whatsoever
- **SC-005**: `composer qa` passes and the whole suite grows

## Assumptions

- **Refuse, do not merge.** Showing a conflict and letting the editor decide is
  the honest answer; merging two versions of prose automatically is a guess
  dressed as a feature. A future feature may offer a comparison; this one refuses
  and says why.
- **The version is a counter maintained by the database layer**, not a timestamp.
  Timestamps collide at the resolution they are stored in, and two saves in the
  same second are exactly the case this feature is about.
- **Taxonomy and accounts are out of scope.** A section is a name and a parent; a
  conflict there loses a word, not an afternoon. Recorded as a limitation rather
  than pretended away.
- **Media records are out of scope** for the same reason — a description and
  alternative text, both short.
