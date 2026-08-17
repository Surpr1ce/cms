# Feature Specification: Taxonomy and Account Administration

**Feature Branch**: `007-taxonomy-and-accounts`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Taxonomy and account administration through EasyAdmin. An editor can create and manage sections and labels; an administrator can manage accounts and what roles they hold. Generic CRUD, using the tool the conventions already name for it, with the domain rules the model already enforces kept intact."

## Overview

There is a hole in what has been built. An editor writing an article can choose a
section and labels from a list, and there is no way to put anything into that
list — sections and labels can only be created by loading fixtures or by hand in
the database. The same is true of accounts: `app:create-administrator` can make
one administrator, and after that nobody can be added without shell access.

This feature closes both. It is also the last part of the stack the conventions
fix that has never been used:

> **Admin**: EasyAdmin 5 (generic CRUD) + hand-written Twig controllers (content, media)

Articles, pages and files were hand-written because each carries rules a generic
screen would fight — a publication workflow, an ownership question, an upload
boundary. Sections, labels and accounts are the other kind: mostly fields, with a
handful of rules the model already enforces. That is what "generic CRUD" was
reserved for.

The risk here is different from the previous features. It is not that something
leaks; it is that a generic tool quietly bypasses a rule the domain holds — that
deleting a section through a scaffolded screen destroys its articles, or that an
account form writes a password in the clear.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Managing sections (Priority: P1)

An editor creates a section, gives it a name and a description, nests it under
another, and later removes it.

**Why this priority**: without it, the section picker on the article screen is a
list nobody can add to.

**Acceptance Scenarios**:

1. **Given** an editor, **When** they create a section with a name, **Then** it is saved with a unique, URL-safe address derived from that name.
2. **Given** an existing section, **When** a second is created with the same name, **Then** both are saved with distinct addresses.
3. **Given** a section, **When** its name is changed, **Then** the change is saved.
4. **Given** a section with articles in it, **When** it is deleted, **Then** the articles survive and become unsectioned.
5. **Given** a section with subsections, **When** it is deleted, **Then** its subsections survive and move up to its former parent.
6. **Given** a section, **When** an attempt is made to place it under itself, **Then** it is refused with an explanation.
7. **Given** an author, **When** they try to reach any section screen, **Then** they are refused.

---

### User Story 2 - Managing labels (Priority: P1)

An editor creates, renames and removes labels.

**Acceptance Scenarios**:

1. **Given** an editor, **When** they create a label, **Then** it is saved with a unique, URL-safe address.
2. **Given** a label carried by articles, **When** it is deleted, **Then** the articles survive and simply lose that label.
3. **Given** an author, **When** they try to reach any label screen, **Then** they are refused.

---

### User Story 3 - Managing accounts (Priority: P1)

An administrator creates an account, sets its display name and roles, changes a
password, and removes an account that owns nothing.

**Why this priority**: until this exists, a second person cannot be given access
without shell access to the server.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** they create an account with an email address, a display name, a password and roles, **Then** it is saved and that person can sign in.
2. **Given** an account, **When** its password is left blank while editing, **Then** the existing password is unchanged.
3. **Given** an account, **When** a new password is entered, **Then** it is stored hashed and the old one no longer works.
4. **Given** any account screen, **When** it is displayed, **Then** no password or hash is shown.
5. **Given** an account that authors articles, **When** deletion is attempted, **Then** it is refused with an explanation naming what is owned.
6. **Given** an administrator's own account, **When** they attempt to delete it, **Then** they are refused.
7. **Given** an editor, **When** they try to reach any account screen, **Then** they are refused.
8. **Given** an existing email address, **When** a second account is created with it, **Then** it is refused.

---

### Edge Cases

- Deleting through a generic screen must obey the same rules the services
  enforce. A scaffolded delete that removed a section's articles, or removed an
  account that still owns content, would be a regression in behaviour introduced
  by a tool rather than by a decision.
- A section or label created with a name that yields no usable address must still
  get one.
- An account created with no roles can sign in and do nothing, which is already
  the model's behaviour and must stay so.
- Changing an account's roles must take effect on that person's next request.

## Requirements *(mandatory)*

### Functional Requirements

**Sections and labels**

- **FR-001**: An editor MUST be able to create, edit and delete sections and labels.
- **FR-002**: A new section or label MUST be given a unique, URL-safe address derived from its name.
- **FR-003**: Renaming MUST NOT silently change an address that content already links to.
- **FR-004**: Deleting a section MUST leave its articles in place, unsectioned, and MUST move its subsections up to its former parent.
- **FR-005**: Deleting a label MUST leave its articles in place.
- **FR-006**: A section MUST NOT be placeable under itself or its own descendant.

**Accounts**

- **FR-007**: An administrator MUST be able to create, edit and delete accounts, and set their roles.
- **FR-008**: A password MUST be stored hashed, and MUST NOT be displayed anywhere.
- **FR-009**: Editing an account without entering a password MUST leave the existing one unchanged.
- **FR-010**: Deleting an account that still owns content MUST be refused, with an explanation naming what is owned.
- **FR-011**: An administrator MUST NOT be able to delete their own account.
- **FR-012**: A duplicate email address MUST be refused.

**Permissions**

- **FR-013**: Section and label screens MUST require the permission to manage taxonomy.
- **FR-014**: Account screens MUST require the permission to manage accounts, which an editor does not hold.
- **FR-015**: Every screen MUST be closed to somebody not signed in.

**Rules are not bypassed**

- **FR-016**: Deletion through these screens MUST go through the same services the rest of the application uses, not through a generic delete.
- **FR-017**: No screen MUST expose a field the domain treats as internal — an address that is generated, a status, a stored credential.

**Evidence**

- **FR-018**: Every screen MUST have a test for the anonymous case and for the insufficient-permission case.
- **FR-019**: Every deletion rule MUST have a test proving the refusal or the survival of what was related.
- **FR-020**: A test MUST prove no account screen displays a password or a hash.

### Key Entities

No new entities.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A section and a label can be created through the browser and immediately chosen on the article screen.
- **SC-002**: A second person can be given access entirely through the browser, with no shell access.
- **SC-003**: 100% of the deletion rules the services enforce still hold when deletion happens through these screens.
- **SC-004**: No password or hash appears in any response from any account screen.
- **SC-005**: Every screen is refused to an anonymous visitor, and account screens are refused to an editor.
- **SC-006**: The existing suite continues to pass unchanged.
- **SC-007**: The quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Screens for articles, pages and media. Those are hand-written and stay so.
- Bulk operations, import and export.
- Reordering sections by dragging.
- Password reset by email, or an account inviting another.
- An audit log.

## Assumptions

- **Addresses are generated on creation and left alone afterwards.** A section's
  address appears in a public URL, so renaming a section does not move it — the
  same reasoning that freezes an article's address at publication.
- **The account form asks for a password only to set one.** Leaving it blank
  while editing means "do not change it", which is the least surprising
  behaviour and avoids a form that cannot be submitted without retyping a
  password.
- **Roles are chosen from the three the model defines**, as checkboxes. There is
  no free-text role field, because an unrecognised role grants nothing and a
  field that accepts one would suggest otherwise.
- **EasyAdmin's own layout is used**, rather than the administration chrome
  written for articles and pages. Two layouts is a cost; matching them is a
  larger one, and the specification is not about appearance.

## Dependencies

- Feature 001: the entities, `CategoryDeleter`, `UserDeleter` and
  `UniqueSlugGenerator`, whose rules this feature must not bypass.
- Feature 003: `AdministrationVoter`, which already decides who may manage
  taxonomy and who may manage accounts.
- Feature 004: the article screen whose section and label pickers this feature
  finally gives something to pick from.
