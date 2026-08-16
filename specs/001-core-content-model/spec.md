# Feature Specification: Core Content Model

**Feature Branch**: `001-core-content-model`

**Created**: 2026-08-16

**Status**: Draft

**Input**: User description: "Core content model: the Doctrine entities that every other part of the CMS builds on — User, Article, Page, Category, Tag and Media — together with their repositories, the initial migration, Foundry factories and tests covering the domain invariants. Article and Page express status changes as intention-revealing methods (publish, unpublish, archive, restore) rather than setters, publishedAt is stamped on the first publish and never overwritten, slugs are unique and URL-safe per entity type, deleting a category leaves its articles uncategorised, and deleting a user who still owns content is refused. No HTTP, admin screens, security configuration or API resources in this feature — persistence and domain behaviour only. The intended model is already described in docs/domain-model.md and must stay the source of truth."

## Overview

This feature establishes the content model the rest of the CMS is built on: what a
piece of content *is*, which states it can be in, how it is organised, and which
rules can never be broken regardless of who or what is changing it.

Nothing in this feature is visible to a reader or an editor yet — there are no
screens, no routes and no API. What it delivers is a set of rules that hold
everywhere, so that the administration area and the public site added later
cannot each invent their own version of "published".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Content moves through a publishing lifecycle (Priority: P1)

An author writes a piece of content, works on it privately, and at some point it
becomes visible to readers. Later it may be taken back out of sight, retired
permanently, or brought back. Each of those is a deliberate act with a defined
meaning, not an arbitrary change of a field.

**Why this priority**: Every other part of the CMS — listings, feeds, the public
site, the API — asks the same question: "is this visible?". If the answer is not
defined once and enforced in one place, each surface answers it differently and
unpublished work leaks.

**Independent Test**: Create content, walk it through every allowed transition
and attempt every forbidden one, and confirm the recorded state and publication
date after each step. No user interface is required to test this.

**Acceptance Scenarios**:

1. **Given** newly created content, **When** it is inspected, **Then** it is a draft and has no publication date.
2. **Given** a draft with a title and body text, **When** it is published, **Then** it becomes published and its publication date is set to the moment of publishing.
3. **Given** content published two weeks ago that was later unpublished, **When** it is published again, **Then** it becomes published and keeps its original publication date.
4. **Given** published content, **When** it is archived, **Then** it is no longer treated as visible but remains retrievable.
5. **Given** archived content, **When** it is restored, **Then** it returns to draft and can be published again.
6. **Given** a draft with no body text, **When** publication is attempted, **Then** the attempt is refused and the content stays a draft.
7. **Given** published content, **When** a further publication is attempted, **Then** the attempt is refused as invalid rather than silently ignored.

---

### User Story 2 - Every piece of content has a stable, readable address (Priority: P1)

Content is identified in a URL by a human-readable name derived from its title.
That name must be safe to put in a URL, must not collide with another piece of
content of the same kind, and must not change under a reader's feet once the
content has been published.

**Why this priority**: Addresses are what search engines, links and bookmarks
depend on. A collision makes one of two pieces of content unreachable; a silent
change breaks every existing link. Both are unrecoverable once the site is live,
so the rule has to exist before anything is published.

**Independent Test**: Create content with titles containing accents, punctuation,
duplicate wording and non-Latin characters, and confirm the generated address in
each case, including the collision and post-publication cases.

**Acceptance Scenarios**:

1. **Given** a title "Hello, World!", **When** the content is created, **Then** its address is `hello-world`.
2. **Given** a title with accented or non-Latin characters, **When** the content is created, **Then** the address contains only lowercase letters, digits and hyphens.
3. **Given** existing content addressed `hello-world`, **When** a second piece of content of the same kind is created with the same title, **Then** the second one receives a distinct address and neither is overwritten.
4. **Given** an article addressed `hello-world`, **When** a *page* is created with the same title, **Then** it may also be addressed `hello-world`, because addresses are unique per kind of content.
5. **Given** published content, **When** its title is edited, **Then** its address does not change.
6. **Given** a draft, **When** its title is edited, **Then** its address is regenerated from the new title.

---

### User Story 3 - Content is organised into sections and topics (Priority: P2)

An editor arranges articles so readers can find related material: each article
sits in at most one section, and carries any number of topic labels. Sections can
nest; labels are flat.

**Why this priority**: Organisation is what turns a pile of articles into a site,
but the site is still usable without it. It depends on User Story 1 existing
first.

**Independent Test**: Create sections and labels, attach them to articles, and
confirm the resulting groupings, including what happens when a section or label
is removed.

**Acceptance Scenarios**:

1. **Given** an article, **When** a section is assigned, **Then** any previously assigned section is replaced, because an article belongs to at most one.
2. **Given** an article, **When** several labels are attached, **Then** all of them are retained.
3. **Given** a section containing articles, **When** the section is deleted, **Then** its articles remain and become unsectioned.
4. **Given** a section with child sections, **When** it is deleted, **Then** its children remain and are attached to its former parent, or become top-level if it had none.
5. **Given** a label attached to articles, **When** the label is deleted, **Then** the articles remain and simply lose that label.
6. **Given** an existing section, **When** another section is created with the same name, **Then** the two are distinguishable by address and neither is lost.

---

### User Story 4 - Standalone pages sit outside the chronological stream (Priority: P2)

Some content — "About", "Contact", "Privacy" — is not news. It has no author
byline and no date in a listing, it can be nested underneath another page, and it
carries an explicit position so a menu can be arranged deliberately.

**Why this priority**: Every real site needs at least a handful of these, and
their fields differ enough from articles that forcing them into the same shape
would leave an article without a meaningful author or a page with a meaningless
one.

**Independent Test**: Create nested pages with explicit positions, publish them,
and confirm the resulting hierarchy and ordering without reference to any author
or date.

**Acceptance Scenarios**:

1. **Given** a page, **When** it is inspected, **Then** it carries no author attribution, section or labels.
2. **Given** two pages, **When** one is nested under the other, **Then** the hierarchy is recorded and the child is reachable from the parent.
3. **Given** several sibling pages with explicit positions, **When** they are listed, **Then** they appear in that order.
4. **Given** a parent page, **When** deletion is attempted while it still has children, **Then** the attempt is refused so that no page is left pointing at a parent that no longer exists.
5. **Given** a page, **When** it is published, unpublished, archived or restored, **Then** it behaves exactly as an article does in User Story 1.

---

### User Story 5 - Uploaded files are catalogued safely (Priority: P3)

A file uploaded to the system is recorded with a generated storage name, its
original name kept only for display, the file type as determined from the file
itself, its size, who uploaded it and when. It can be attached to an article or a
page as that content's lead image, and it carries alternative text so that
content using it can be read by people who cannot see it.

**Why this priority**: Content is usable without images, so this can follow. It is
recorded now because the storage-name rule has to exist before any upload path is
built on top of it.

**Independent Test**: Catalogue file entries, attach them to content, and confirm
that the stored name is never the supplied one and that deleting an entry leaves
the content intact.

**Acceptance Scenarios**:

1. **Given** a file whose supplied name is `../../evil.php`, **When** it is catalogued, **Then** the stored name is a generated value that contains no path separators and does not end in an executable extension, and the supplied name is retained only as display text.
2. **Given** a catalogued file, **When** its recorded type is inspected, **Then** the type reflects the file's content rather than its file extension.
3. **Given** a catalogued file used as an article's lead image, **When** the file entry is deleted, **Then** the article remains and simply has no lead image.
4. **Given** a catalogued file with no alternative text, **When** it is attached to content as a lead image, **Then** the attempt is refused.

---

### User Story 6 - Accounts cannot be removed out from under their content (Priority: P3)

Each account has an email address used to sign in, a stored credential that is
never held in readable form, a display name shown as the author byline, and one
or more roles. An account that still owns content cannot be deleted.

**Why this priority**: The rule matters the moment more than one person uses the
system, but it is only reachable once content exists to be owned.

**Independent Test**: Create accounts, attribute content to them, and attempt
deletion in both the owning and non-owning case.

**Acceptance Scenarios**:

1. **Given** an existing account, **When** a second account is created with the same email address, **Then** the attempt is refused.
2. **Given** an account that has authored articles, **When** deletion is attempted, **Then** the attempt is refused and the account and its content both remain.
3. **Given** an account that has uploaded files, **When** deletion is attempted, **Then** the attempt is refused.
4. **Given** an account that owns no content, **When** it is deleted, **Then** it is removed.
5. **Given** any account, **When** its stored credential is inspected, **Then** it is not the readable password.

---

### Edge Cases

- A title consisting entirely of punctuation or non-Latin script produces no
  usable address. The system must still produce a unique, URL-safe address rather
  than an empty one.
- Two pieces of content created in the same instant with identical titles must
  still end up with distinct addresses.
- Publishing content whose title is only whitespace must be refused; whitespace
  is not a title.
- An account that authored an article which was later archived still owns it —
  archiving is not a release of ownership, so deletion is still refused.
- A section may not be made its own ancestor, directly or through a chain of
  parents; the same holds for nested pages.
- Content may be attributed only to an account that exists.

## Requirements *(mandatory)*

### Functional Requirements

**Publication lifecycle**

- **FR-001**: The system MUST record every article and page in exactly one of three states: draft, published, or archived.
- **FR-002**: Newly created articles and pages MUST start as drafts with no publication date.
- **FR-003**: The system MUST expose state changes as four named acts — publish, unpublish, archive, restore — and MUST NOT allow the state to be set to an arbitrary value directly.
- **FR-004**: The system MUST permit only these transitions: draft → published, published → draft, published → archived, draft → archived, archived → draft. Any other transition MUST be refused.
- **FR-005**: The system MUST set the publication date at the moment of the first successful publication and MUST NOT change it on any later publication.
- **FR-006**: The system MUST guarantee that published content always has a publication date, and that draft content that has never been published has none.
- **FR-007**: The system MUST refuse to publish content that has no title or no body text.

**Addresses**

- **FR-008**: The system MUST derive a readable address from the title of every article, page, section and label.
- **FR-009**: Addresses MUST contain only lowercase letters, digits and hyphens, MUST NOT begin or end with a hyphen, and MUST NOT be empty.
- **FR-010**: Addresses MUST be unique within a kind of content, and MAY repeat across different kinds.
- **FR-011**: When a generated address is already taken, the system MUST derive a distinct one rather than failing or overwriting.
- **FR-012**: The system MUST regenerate the address when the title of unpublished content changes, and MUST leave it unchanged once the content has been published.

**Organisation**

- **FR-013**: The system MUST allow an article to belong to at most one section and to carry any number of labels.
- **FR-014**: Sections MUST be nestable to any depth; labels MUST be flat.
- **FR-015**: The system MUST refuse to make a section or a page its own ancestor.
- **FR-016**: Deleting a section MUST leave its articles in place, unsectioned, and MUST re-attach its child sections to its former parent.
- **FR-017**: Deleting a label MUST leave its articles in place without that label.
- **FR-018**: The system MUST refuse to delete a page that still has child pages.
- **FR-019**: Pages MUST carry an explicit position used to arrange siblings, and MUST NOT carry an author, a section or labels.

**Files**

- **FR-020**: The system MUST record, for every catalogued file, a generated storage name, the supplied name as display text, the file type, the size in bytes, the uploading account, and the moment of upload.
- **FR-021**: The storage name MUST be generated by the system and MUST NOT be derived from the supplied name.
- **FR-022**: The recorded file type MUST be determined from the file's content, not from its name or extension.
- **FR-023**: The system MUST refuse to attach a file to content as a lead image while the file has no alternative text.
- **FR-024**: Deleting a catalogued file MUST leave any content that referenced it intact, with no lead image.

**Accounts**

- **FR-025**: The system MUST identify accounts by a unique email address and MUST refuse a duplicate.
- **FR-026**: The system MUST store credentials in a non-reversible form and MUST NOT expose or record them in readable form anywhere, including logs and error output.
- **FR-027**: The system MUST record one or more roles per account, drawn from administrator, editor and author.
- **FR-028**: The system MUST refuse to delete an account that still authors content or has catalogued files, in any state including archived.
- **FR-029**: Every article MUST be attributed to an account that exists.

**Retrieval**

- **FR-030**: The system MUST provide retrieval of content by address, of published content in reverse chronological order, and of articles by section and by label.
- **FR-031**: Retrieval MUST be able to restrict results to published content, so that no caller has to reimplement the definition of "visible".

**Evidence**

- **FR-032**: Every rule in FR-001 to FR-031 that can be violated MUST have an automated test that proves the violation is refused, not only that the permitted path works.
- **FR-033**: Every kind of content MUST have a reusable way to produce realistic test instances, so tests do not construct content by hand.
- **FR-034**: The stored shape of the model MUST be reproducible from scratch on an empty database in a single documented step.

### Key Entities

- **Account**: someone who can sign in and be credited. Email address (unique, used to sign in), stored credential, display name shown as a byline, roles, and the moment the account was created.
- **Article**: dated content that appears in listings and feeds. Title, address, optional summary, body text, state, publication date, author, at most one section, any number of labels, an optional lead image, and creation and modification times.
- **Page**: standalone content outside the chronological stream. The same title, address, summary, body, state and publication date as an article, plus an optional parent page and an explicit position — and no author, section or labels.
- **Section**: an exclusive grouping answering "what part of the site is this in". Name, address, optional description, optional parent section.
- **Label**: a non-exclusive topic marker answering "what is this about". Name and address only.
- **File**: a catalogued upload. Generated storage name, supplied name kept for display, type determined from content, size, optional alternative text, uploading account, and moment of upload.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Every one of the six kinds of content described above can be created, retrieved, changed and removed, with all rules in this specification enforced, and none of the rules can be bypassed by any caller.
- **SC-002**: 100% of the transition rules and deletion rules in this specification have a test that demonstrates the forbidden case being refused.
- **SC-003**: Content that has never been published never appears in any result set restricted to published content, across 100% of the retrieval operations provided.
- **SC-004**: An address generated from any title — including titles made only of punctuation, accents or non-Latin script — is always usable in a URL and never collides with an existing one of the same kind.
- **SC-005**: A publication date, once set, is identical after any number of subsequent unpublish and publish cycles.
- **SC-006**: A new contributor can bring an empty database to the full shape of this model in a single documented step, with no manual editing.
- **SC-007**: The project's quality gate passes on this work with no rule relaxed, no suppression added, and no test skipped.

## Out of Scope

The following are deliberately excluded from this feature and are expected to
build on it later:

- Sign-in, session handling, permission checks and ownership-based authorisation.
- Any administration screen, whether generic or hand-written.
- Any public-facing page, listing, feed or template.
- The read-only JSON API.
- The upload mechanism itself — receiving a file, validating it, writing it to
  disk and serving it back. This feature catalogues files; it does not move them.
- A rich-text editor, or any sanitisation of the body text supplied by one.

## Assumptions

Made where the feature description and `docs/domain-model.md` did not settle a
detail, and open to correction:

- **Body text is trusted at this layer.** It is stored as supplied. Sanitising it
  belongs with the editor that produces it and the templates that render it, both
  out of scope here.
- **Addresses freeze at first publication, not at creation.** A draft's title can
  be corrected freely; once readers can link to it, the address stops moving. The
  system does not offer a way to change it afterwards in this feature.
- **Address collisions are resolved by appending a numeric suffix** (`hello-world`,
  `hello-world-2`, …). Any deterministic scheme satisfies the requirement; this
  one is chosen for readability.
- **A title that yields no usable characters** falls back to a generated address
  rather than being refused, so that a draft can always be saved.
- **Deleting a section re-attaches its children to its former parent** rather than
  deleting them or leaving them orphaned. This matches the treatment of its
  articles: deleting a grouping never destroys what it grouped.
- **Pages are stricter than sections about deletion** — a page with children is
  refused rather than re-parented, because page nesting is also the menu
  structure, and silently re-arranging a visitor's navigation is worse than
  refusing the deletion.
- **Ownership blocking account deletion covers authored articles and catalogued
  files.** Pages have no author, so they do not block.
- **Roles are stored as a plain list on the account**, not as a separate entity.
  Three roles are enough that a management screen for them would be unused.
- **The lead image is a single optional file per article or page.** Galleries and
  in-body media are not modelled.
- **Alternative text is required at the point a file is used as a lead image**,
  not at the point it is catalogued, so that cataloguing an upload never fails on
  a field the uploader has not filled in yet.

## Dependencies

- A running PostgreSQL 16 instance on the development machine — satisfied; see
  `docs/adr/0003-postgresql-natively-instead-of-docker.md`.
- `docs/domain-model.md` remains the source of truth for the intended model. Where
  this specification adds detail, that document is updated in the same change.
