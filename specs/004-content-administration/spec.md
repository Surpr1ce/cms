# Feature Specification: Content Administration

**Feature Branch**: `004-content-administration`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Content administration: hand-written admin screens for articles and pages, with the publication workflow and sanitised markup. Somebody signed in can list, create, edit and delete content, and move it through the publication states — subject to the permissions feature 003 decided. Markup an author submits is sanitised before it is stored. No taxonomy, media or account screens in this feature."

## Overview

Feature 003 fitted a lock to an empty room. This is the room: the screens through
which somebody actually writes, edits and publishes.

It is also the feature that inherits an obligation. `docs/status.md` has carried
this since feature 002:

> **Content markup is rendered unsanitised.** This is safe only because there is
> no editor yet and the only author is a developer loading fixtures. Whichever
> feature first lets somebody paste markup into the CMS inherits this obligation,
> and it is the single most important thing to get right in the administration
> feature.

This is that feature. From the moment these screens exist, somebody with the
author role — the least trusted role there is — can put markup into a page that
every reader of the site will load. Getting that wrong means an author can run
script in an editor's browser, and from there do anything an editor can do.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Writing and editing (Priority: P1)

Somebody signed in sees the content they are allowed to see, opens one, changes
it, and saves. What they may open and what they may change is decided by the
rules feature 003 already established.

**Why this priority**: it is the reason a content management system exists.

**Independent Test**: sign in as each role, list content, and open and save an
item each role is and is not permitted to touch.

**Acceptance Scenarios**:

1. **Given** an author, **When** they open the content list, **Then** they see their own articles and the ones already published, and nothing else.
2. **Given** an editor, **When** they open the content list, **Then** they see everything.
3. **Given** an author and their own draft, **When** they open it for editing and save a change, **Then** the change is stored and they are told it saved.
4. **Given** an author and somebody else's draft, **When** they request its editing address directly, **Then** they are refused.
5. **Given** an author and their own published article, **When** they request its editing address, **Then** they are refused, because it is no longer theirs alone.
6. **Given** an editor and anybody's article in any state, **When** they open it for editing, **Then** they may.
7. **Given** any form, **When** it is submitted with a title missing, **Then** the form comes back with the problem named and nothing is stored.
8. **Given** any form, **When** it is submitted without the expected one-time token, **Then** it is refused.

---

### User Story 2 - Markup is sanitised before it is stored (Priority: P1)

What an author writes is stored in a form that cannot execute. Formatting they
legitimately use survives; anything that could run does not.

**Why this priority**: this is the requirement whose failure is worst and
quietest. An article renders correctly, looks right in review, and runs somebody
else's script in every reader's browser — including the editor who publishes it.
An author is the least trusted role in the system and this feature is the first
to hand them a text box.

**Independent Test**: submit a catalogue of hostile markup through the form and
inspect what was stored, not what was displayed.

**Acceptance Scenarios**:

1. **Given** body text containing a script element, **When** it is saved, **Then** what is stored contains no script element.
2. **Given** body text containing an event handler attribute such as `onclick` or `onerror`, **When** it is saved, **Then** what is stored contains no such attribute.
3. **Given** body text containing a link whose target is a script URL, **When** it is saved, **Then** the stored link cannot execute.
4. **Given** body text containing an inline frame, an object or an embed, **When** it is saved, **Then** none of them is stored.
5. **Given** ordinary formatting — headings, paragraphs, emphasis, lists, links, images, quotes, code — **When** it is saved, **Then** all of it survives intact.
6. **Given** the sanitised text, **When** it is read back and rendered on the public site, **Then** it renders as the author intended.
7. **Given** hostile markup, **When** it is saved and then read back, **Then** what a reader receives is what was stored — sanitising happens once, on the way in, not on every render.
8. **Given** a title or a summary containing markup, **When** it is saved, **Then** the markup is not stored as markup, because those fields are text and never markup.

---

### User Story 3 - Moving content through its states (Priority: P1)

Content is published, taken down, archived and restored from the screen, by
somebody permitted to do it, using the transitions the domain model already
defines.

**Why this priority**: publishing is the act the whole model was built around,
and it is the one an author must not be able to perform.

**Acceptance Scenarios**:

1. **Given** an editor and a draft with a title and body, **When** they publish it, **Then** it becomes visible on the public site and its publication date is set.
2. **Given** an editor and a published article, **When** they unpublish it, **Then** it disappears from the public site and its publication date does not change.
3. **Given** an editor and content, **When** they archive and then restore it, **Then** it returns as a draft.
4. **Given** an author, **When** they open their own draft, **Then** no control to publish it is shown.
5. **Given** an author, **When** they submit a publish request directly for any content, **Then** they are refused.
6. **Given** an editor and a draft with no body, **When** they try to publish it, **Then** they are told why and it stays a draft.
7. **Given** any state change, **When** it is submitted without the expected one-time token, **Then** it is refused.

---

### User Story 4 - Creating and deleting (Priority: P2)

New content is created from the screen and given an address automatically.
Deleting is possible for those permitted, and is confirmed rather than immediate.

**Acceptance Scenarios**:

1. **Given** an author, **When** they create an article, **Then** it is attributed to them and starts as a draft.
2. **Given** a new article titled "Hello, World!", **When** it is created, **Then** its address is `hello-world`, or a distinct one if that is taken.
3. **Given** an author and their own draft, **When** they delete it, **Then** it is gone and they are returned to the list.
4. **Given** an author and somebody else's content, **When** they submit a delete request, **Then** they are refused.
5. **Given** a delete request without the expected one-time token, **When** it is submitted, **Then** it is refused.
6. **Given** an editor, **When** they create a standalone page, **Then** they may set its parent and its menu position.
7. **Given** an author, **When** they look for a way to create a page, **Then** there is none — pages are an editorial concern.

---

### Edge Cases

- Two people editing the same article: the second save wins silently. This is
  accepted for now and stated, rather than discovered.
- An article whose title is changed while it is a draft must have its address
  regenerated — this is the gap feature 001 recorded and this feature closes,
  because the administration layer is the single entry point it was waiting for.
- An article whose title is changed after publication must keep its address.
- Deleting a page with children must be refused with an explanation, not an
  error page.
- A form submitted with a section or a label that has since been deleted must
  not fail with an unhandled error.
- Somebody whose role is reduced while an edit form is open must be refused when
  they submit it, not when they opened it.

## Requirements *(mandatory)*

### Functional Requirements

**Sanitising**

- **FR-001**: Markup submitted as body text MUST be sanitised before it is stored.
- **FR-002**: Sanitising MUST remove script elements, event-handler attributes, inline frames, objects, embeds, and any link or image target that could execute.
- **FR-003**: Sanitising MUST preserve headings, paragraphs, emphasis, strong text, ordered and unordered lists, links, images, block quotes, code and preformatted text, and tables.
- **FR-004**: Sanitising MUST happen once, on the way in. What is read back MUST be exactly what a reader receives.
- **FR-005**: Title and summary fields MUST NOT store markup at all.
- **FR-006**: The sanitiser MUST be applied to every path that stores body text, so that adding a screen cannot bypass it.

**Permissions**

- **FR-007**: Every administration address MUST enforce the permission that governs it, using the voters feature 003 established.
- **FR-008**: A listing MUST show only content the viewer is permitted to see.
- **FR-009**: A control the viewer is not permitted to use MUST NOT be shown, and MUST also be refused if submitted directly.
- **FR-010**: Every state-changing request MUST require the expected one-time token.

**Writing**

- **FR-011**: Content MUST be creatable, editable and deletable from the screen, subject to permission.
- **FR-012**: A new article MUST be attributed to whoever created it and MUST start as a draft.
- **FR-013**: A new piece of content MUST be given a unique, URL-safe address derived from its title.
- **FR-014**: Changing the title of unpublished content MUST regenerate its address; changing it after publication MUST NOT.
- **FR-015**: Validation failures MUST return the form with the problem named and MUST store nothing.
- **FR-016**: Deletion MUST be confirmed before it happens.
- **FR-017**: A refused deletion — a page with children — MUST explain itself rather than produce an error page.

**Publishing**

- **FR-018**: Publish, unpublish, archive and restore MUST be available from the screen to those permitted.
- **FR-019**: A refused publication MUST explain why and leave the content as it was.
- **FR-020**: Pages MUST additionally offer a parent and a menu position.

**Evidence**

- **FR-021**: Every administration address MUST have a test for the anonymous case and for the insufficient-permission case.
- **FR-022**: Sanitising MUST have a test per class of hostile input, asserting on what was **stored**, not on what was displayed.
- **FR-023**: Every rule that refuses something MUST have a test proving the refusal.

### Key Entities

No new entities.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: No hostile input in the test catalogue survives storage in an executable form.
- **SC-002**: 100% of ordinary formatting survives sanitising unchanged.
- **SC-003**: Every administration address is unreachable anonymously and refuses the insufficient-permission case, verified per address.
- **SC-004**: An author cannot publish, cannot touch anybody else's content, and cannot reach any page screen — verified by test rather than by the absence of a button.
- **SC-005**: A complete article can be written, saved, published and read on the public site without leaving the browser.
- **SC-006**: The public site behaves as before, verified by the existing suite continuing to pass unchanged.
- **SC-007**: The quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Screens for sections, labels, files and accounts. Those are the generic CRUD
  the conventions assign to EasyAdmin, and they are a separate feature.
- Uploading files. The lead-image picker chooses from what is already
  catalogued; putting bytes on disk is feature 005.
- A rich-text editor. The body is a plain text area containing markup. An editor
  is a large decision of its own and sanitising must not depend on one.
- Revisions, drafts-of-published, scheduled publishing, and preview of
  unpublished content at a public address.
- Optimistic locking for concurrent edits.

## Assumptions

- **Sanitising happens on the way in, not on the way out.** Both are defensible;
  storing sanitised text means a reader is served exactly what was reviewed, and
  it means a template that forgets to sanitise cannot exist. The cost is that
  changing the policy does not retroactively clean old content, which is
  accepted and recorded.
- **The body is a plain text area.** Somebody typing markup into it is the
  expected use for now.
- **Last write wins** on concurrent edits.
- **An author sees published articles they did not write** in the listing, read
  only. They cannot open them, and hiding them would make the list confusing.
- **The lead-image picker lists what is already catalogued.** Until feature 005,
  that is whatever the fixtures created.

## Dependencies

- Feature 001: the entities, the transitions and `UniqueSlugGenerator`.
- Feature 002: the public site, which is what publishing makes content appear on.
- Feature 003: the voters and the gate. This feature adds no permission rule; it
  asks the ones that exist.
- **A new dependency is required**: an HTML sanitiser. Nothing in the project can
  do this today, and writing one would be the worst possible place to be
  inventive. This is recorded as an ADR.
