---

description: "Specification for feature 016 — an administration area that is one thing"
---

# Feature Specification: Administration Interface

**Feature Branch**: `016-admin-interface`
**Created**: 2026-08-17
**Status**: Draft
**Input**: Two screenshots and a question. The screenshots showed the page editing form with a label running into an invisible field; the question was "why is `/admin/manage` made completely differently from pages, files and articles?"

## Why this feature exists

Fifteen features and 871 passing tests had produced an administration area that
was unusable in two different ways, neither of which any test could see.

**The forms were not styled at all.** `form_row()` renders Symfony's default
markup — a `div`, a bare `label`, a bare `input` — and Tailwind's preflight
strips the border and padding a browser would otherwise give those. The result
was "TitleTerms of service" on one line, with nothing to type into that anybody
could see, and a body field that was a hairline. Every test passed throughout,
because the crawler finds fields by name and does not care what they look like.

**There were two administration areas.** Articles, pages and files were
hand-written Twig with Tailwind; sections, labels and accounts were EasyAdmin,
with its own layout, typeface, controls and navigation. `CLAUDE.md` had said so
since before anything was built — "EasyAdmin 5 (generic CRUD) + hand-written Twig
controllers" — and the decision was defensible on paper and wrong in practice.
Somebody using this every day moves between those screens constantly, and the
seam is visible every time.

**And the landing page said nothing.** Four links with nothing behind them, on a
site that by then held articles in three states, pages, files, a search index and
a log.

The connecting fault is worth naming: **a test suite proves the rules hold, not
that somebody opening the thing can use it.** This is the second feature in a row
whose entire content came from looking at the running site.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The forms can be used (Priority: P1)

Every field is visible, labelled above itself, and sized for what goes in it.

**Independent Test**: Open each administration form and look at it.

**Acceptance Scenarios**:

1. **Given** any form in the application, **When** it is rendered, **Then**
   every control carries the border, padding and width the rest of the site uses
2. **Given** a label, **When** it is rendered, **Then** it sits above its field
   rather than beside it
3. **Given** a field with help text, **When** it is rendered, **Then** the help
   is beneath the field and visibly secondary
4. **Given** a field with an error, **When** it is rendered, **Then** the message
   is beneath that field and the field itself is marked
5. **Given** a form added in future, **When** it is rendered, **Then** it is
   styled without anybody remembering to ask

### User Story 2 - The administration area is one thing (Priority: P1)

Sections, labels and accounts look and behave like articles, pages and files.

**Independent Test**: Move between the screens and look for a seam.

**Acceptance Scenarios**:

1. **Given** any administration screen, **When** it is opened, **Then** it
   carries the same navigation, typeface and controls as every other
2. **Given** the sections, labels and accounts screens, **When** they are used,
   **Then** every rule the generic screens were overridden to keep still holds:
   an address is generated once and then fixed, deleting a section keeps its
   articles, an account's stored hash is never rendered
3. **Given** an editor, **When** they open the manage area, **Then** they reach
   sections and labels and are refused accounts
4. **Given** an author, **When** they try any of it, **Then** they are refused

### User Story 3 - The landing page answers something (Priority: P2)

Somebody arriving sees how much is waiting, what they left unfinished, and what
happened while they were away.

**Independent Test**: Sign in as each kind of account and read the first screen.

**Acceptance Scenarios**:

1. **Given** somebody signing in, **When** they land, **Then** they see counts of
   what exists — and only of the things they may open
2. **Given** an author with unfinished drafts, **When** they land, **Then** their
   own drafts are listed
3. **Given** an administrator, **When** they land, **Then** the most recent
   entries from the log are shown
4. **Given** an editor or author, **When** they land, **Then** nothing about the
   log or accounts appears

### Edge Cases

- **A count is not a link if the viewer cannot open what it counts.** A number
  somebody can neither act on nor verify is not information.
- **A checkbox reads better beside its label than beneath it**, unlike every
  other control.
- **Replacing the generic screens must not lose the rules they enforced.** They
  were overridden for reasons, and the reasons outlive the bundle.
- **Removing EasyAdmin removes the only thing that needed `unsafe-inline` for
  styles.** That concession should go with it rather than being left behind.

## Requirements *(mandatory)*

**Forms**

- **FR-001**: A form theme MUST style every control the application uses
- **FR-002**: It MUST be registered globally, so a form added later is styled
  without anybody remembering
- **FR-003**: Labels MUST sit above their fields; help beneath; errors beneath
  and attached to the field they concern
- **FR-004**: A field with an error MUST be visibly marked

**One administration area**

- **FR-005**: Sections, labels and accounts MUST be served by hand-written
  screens in the same layout as the rest
- **FR-006**: Every rule the generic screens enforced MUST still hold and MUST be
  tested: generated-and-then-fixed addresses, articles surviving a section's
  deletion, subsections moving up, a hash never rendered, blank meaning unchanged,
  self-deletion refused, an owning account refused with an explanation
- **FR-007**: EasyAdmin MUST be removed as a dependency
- **FR-008**: `style-src` MUST stop allowing `unsafe-inline`

**The landing page**

- **FR-009**: It MUST show counts of what exists, limited to what the viewer may
  open
- **FR-010**: It MUST list the viewer's own unfinished drafts
- **FR-011**: It MUST show recent log entries to whoever may read the log, and to
  nobody else

## Success Criteria *(mandatory)*

- **SC-001**: Every administration form can be filled in by looking at it
- **SC-002**: No screen in the administration area looks like it belongs to a
  different application
- **SC-003**: Every rule the generic screens held is still held and still tested
- **SC-004**: The content security policy is strictly tighter than before
- **SC-005**: Somebody arriving at `/admin` learns something
- **SC-006**: `composer qa` passes

## Assumptions

- **Replace rather than theme.** Making EasyAdmin look like the rest means
  fighting a bundle's own stylesheet with Tailwind for as long as both exist.
  Three simple CRUD screens are less code than that fight and are ours to keep
  consistent.
- **`sections`, `labels` and `accounts` in the addresses**, matching the words
  the interface uses, rather than `category`, `tag` and `user`.
- **The dashboard shows five recent things.** Enough to be useful on arrival,
  few enough that nobody scrolls past it to reach the links.
