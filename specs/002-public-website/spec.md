# Feature Specification: Public Website

**Feature Branch**: `002-public-website`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Public website: the server-rendered Twig frontend that finally makes the CMS visible to a reader. A home page listing published articles newest first with paging, an article page at its own address, section and label listings, standalone pages reachable by address with the nested menu built from their explicit order, and a 404 that is a real page rather than a stack trace. Only published content is ever reachable; a draft or archived address returns 404, not a redirect and not an empty page, so the existence of unpublished work is not disclosed. Layout, navigation and styling come from AssetMapper and Tailwind with no Node build step. No administration screens, no sign-in, no JSON API and no media upload in this feature — read-only public delivery on top of the content model that feature 001 delivered."

## Overview

Feature 001 built a complete content model with no way to see it: every URL on the
site returns 404, deliberately. This feature is the other half — the reader's
view.

It is also the first feature that puts anything on the public internet, which
changes what "correct" means. Until now a mistake meant a failing test. From here
a mistake means a draft article visible to a stranger, or the fact that a draft
exists disclosed to somebody who should not know.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A reader finds and reads an article (Priority: P1)

Somebody arrives at the site, sees the most recent published writing, picks
something, and reads it. The address they land on is one they can bookmark, send
to somebody else, and return to.

**Why this priority**: it is the entire point of a content management system.
Everything else on this list is navigation towards it.

**Independent Test**: publish a handful of articles, open the home page, follow a
link, and confirm the article renders with its title, date, author and body at a
stable address.

**Acceptance Scenarios**:

1. **Given** several published articles, **When** the home page is opened, **Then** they appear newest first, each showing its title, publication date, author and summary.
2. **Given** an article listed on the home page, **When** its link is followed, **Then** the article page opens at that article's own address and shows its full body.
3. **Given** an article with a lead image, **When** its page is opened, **Then** the image appears with its alternative text available to a screen reader.
4. **Given** an article with no summary, **When** it appears in a listing, **Then** the listing degrades gracefully rather than showing an empty gap.
5. **Given** more articles than fit on one page, **When** the home page is opened, **Then** only the first page is shown and there is a way to reach the next.
6. **Given** the last page of the listing, **When** it is reached, **Then** there is no link to a further page.

---

### User Story 2 - Unpublished work is invisible, and its existence is not disclosed (Priority: P1)

A draft or archived article must be unreachable to a reader. Not merely
unlisted — unreachable, and indistinguishable from something that never existed.

**Why this priority**: this is the one requirement in the feature that cannot be
fixed after the fact. An article shown early has been shown. It is also the
easiest to get subtly wrong: hiding content from listings while leaving it
readable by address is a mistake that passes every happy-path test.

**Independent Test**: take the address of a draft and of an archived article and
request each directly, without going through any listing.

**Acceptance Scenarios**:

1. **Given** a draft article, **When** its address is requested directly, **Then** the response is the same not-found page a nonexistent address produces.
2. **Given** an archived article, **When** its address is requested directly, **Then** the response is not-found.
3. **Given** a draft page, **When** its address is requested directly, **Then** the response is not-found.
4. **Given** a draft article, **When** any listing is opened, **Then** it does not appear and the count of results does not include it.
5. **Given** an article that was published and then unpublished, **When** its address is requested, **Then** the response is not-found, not a redirect and not a message explaining that it was withdrawn.
6. **Given** a draft and a nonexistent address, **When** each is requested, **Then** the two responses are indistinguishable to the reader — same status, same page, no timing or wording that separates them.
7. **Given** a section that contains only drafts, **When** its listing is opened, **Then** it renders as an empty section rather than disclosing that there is hidden content in it.

---

### User Story 3 - A reader browses by section and by topic (Priority: P2)

Having read something, a reader wants more of the same kind. Sections answer
"more from this part of the site"; labels answer "more about this subject".

**Why this priority**: it turns a list of articles into a site a person can move
around in. It depends on User Story 1 existing first.

**Independent Test**: assign sections and labels to published articles, open each
listing, and confirm it contains what it should and nothing else.

**Acceptance Scenarios**:

1. **Given** a section with published articles, **When** its listing is opened, **Then** those articles appear newest first, with the section's name and description shown.
2. **Given** an article in a section, **When** its page is opened, **Then** its section is named and links to that section's listing.
3. **Given** an article carrying labels, **When** its page is opened, **Then** each label is shown and links to that label's listing.
4. **Given** a label carried only by unpublished articles, **When** its listing is opened, **Then** it renders as empty rather than as not-found, because the label itself exists.
5. **Given** a section with subsections, **When** its listing is opened, **Then** the subsections are shown so a reader can go deeper.
6. **Given** an address of a section that does not exist, **When** it is requested, **Then** the response is not-found.

---

### User Story 4 - Standalone pages and the site menu (Priority: P2)

"About", "Contact", "Privacy" are reachable from every page through a menu whose
order somebody chose, with nested pages appearing under their parent.

**Why this priority**: every real site has a handful of these, and the menu is how
a reader orients. The content model already carries the explicit order; this makes
it visible.

**Independent Test**: publish nested pages with explicit positions, open any page
of the site, and confirm the menu reflects that structure.

**Acceptance Scenarios**:

1. **Given** published top-level pages with explicit positions, **When** any page of the site is opened, **Then** the menu lists them in that order.
2. **Given** a page nested under another, **When** the menu is rendered, **Then** the child appears under its parent, not alongside it.
3. **Given** a draft page, **When** the menu is rendered, **Then** it does not appear.
4. **Given** a published page, **When** its address is opened, **Then** its title and body are shown without an author or a date, because a page has neither.
5. **Given** a nested page, **When** its address is opened, **Then** a reader can tell where in the structure they are.

---

### User Story 5 - Errors are pages, not stack traces (Priority: P2)

A reader who requests something that is not there gets a page belonging to this
site — its layout, its menu, a way back — rather than a framework error screen.

**Why this priority**: it is what makes User Story 2 safe to rely on. A
not-found page that leaks a file path or a class name discloses more than the
article would have.

**Independent Test**: request a nonexistent address with the site in its
production configuration and inspect what comes back.

**Acceptance Scenarios**:

1. **Given** an address that matches nothing, **When** it is requested, **Then** a not-found page renders inside the site's own layout with the menu present.
2. **Given** any error page, **When** it is inspected, **Then** it contains no file path, class name, query or stack trace.
3. **Given** the not-found page, **When** the response status is inspected, **Then** it is 404 rather than 200, so that search engines and link checkers are told the truth.
4. **Given** an unexpected failure, **When** it occurs, **Then** the reader sees a generic error page and the detail is recorded in the log rather than on screen.

---

### Edge Cases

- A section or label listing with no published content must render as an empty
  listing, not as not-found — the section exists, it simply has nothing to show.
- An article whose author account has been renamed shows the current display
  name; nothing about attribution is cached in the article.
- A page nested three or more levels deep must still render a usable menu.
- A listing page requested beyond the last page of results shows an empty
  listing rather than an error, and still returns a successful status.
- A negative or non-numeric page number must not produce an error page or a
  database query with a negative offset.
- An article with a lead image whose file is missing from disk must still render
  the article; the image is not the article.
- Content containing markup produced by an editor must render as the author
  intended without allowing the page's own layout to be broken.

## Requirements *(mandatory)*

### Functional Requirements

**Visibility — the rules that cannot be got wrong**

- **FR-001**: The system MUST make only published content reachable on any public address.
- **FR-002**: A request for the address of draft or archived content MUST produce the same not-found response as a request for an address that has never existed, with no difference in status, wording or structure.
- **FR-003**: The system MUST NOT redirect away from an unpublished address, and MUST NOT explain that content was withdrawn.
- **FR-004**: No listing, count, menu, or link on any public page MUST include unpublished content.
- **FR-005**: Every public page MUST be reachable without signing in, and MUST NOT expose any control that only an authenticated user could use.

**Reading**

- **FR-006**: The home page MUST list published articles newest first.
- **FR-007**: Listings MUST be paginated, and MUST offer navigation to the next and previous page only where one exists.
- **FR-008**: An article page MUST show its title, publication date, author display name, body, section if it has one, labels if it has any, and lead image if it has one.
- **FR-009**: A lead image MUST be rendered with its alternative text.
- **FR-010**: A standalone page MUST show its title and body, and MUST NOT show an author or a date.

**Navigation**

- **FR-011**: Every page MUST carry the site menu, built from published standalone pages in their explicit order, with nested pages shown under their parent.
- **FR-012**: A section listing MUST show the section's name, its description if it has one, its subsections, and its published articles newest first.
- **FR-013**: A label listing MUST show the label's name and its published articles newest first.
- **FR-014**: An article page MUST link to its section and to each of its labels.
- **FR-015**: A section or label that exists but has no published content MUST render as an empty listing, not as not-found.

**Addresses**

- **FR-016**: Every piece of content MUST be reachable at an address derived from the one stored with it.
- **FR-017**: Article, page, section and label addresses MUST NOT collide with each other; a reader following any of them MUST reach the thing they expected.
- **FR-018**: An address that matches nothing MUST produce not-found.

**Errors**

- **FR-019**: Not-found and error responses MUST render inside the site's own layout.
- **FR-020**: Error pages MUST NOT disclose file paths, class names, queries, stack traces or software versions.
- **FR-021**: A not-found response MUST carry the not-found status, and an unexpected failure MUST carry a server-error status.

**Robustness**

- **FR-022**: An out-of-range, negative or non-numeric page number MUST be handled without an error and without a malformed query.
- **FR-023**: A missing lead-image file MUST NOT prevent the article from rendering.
- **FR-024**: Content markup MUST render as authored without being able to break the page's own layout or scripts.

**Evidence**

- **FR-025**: Every public address MUST have a test covering the anonymous-reader case, and every rule under "Visibility" MUST have a test proving the unpublished case is refused.
- **FR-026**: The pagination boundaries — first page, last page, beyond the last page, and an invalid page number — MUST each have a test.

### Key Entities

No new entities. This feature reads what feature 001 defined: **Article**,
**Page**, **Category** (a section), **Tag** (a label), **Media** (a file) and
**User** (an author, appearing only as a display name).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of draft and archived content is unreachable by direct address, verified by a test for each content type.
- **SC-002**: A draft address and a nonexistent address produce byte-identical responses apart from nothing — no reader can distinguish them.
- **SC-003**: A reader can reach any published article from the home page in at most three clicks, through the listing, a section, or a label.
- **SC-004**: Every public address renders for a reader who has never signed in, with no error, across 100% of the addresses the site exposes.
- **SC-005**: No error page discloses a file path, class name, query, stack trace or version string.
- **SC-006**: The site renders and is navigable on a narrow screen as well as a wide one.
- **SC-007**: A page of a listing issues a number of database queries that does not grow with the number of items on it.
- **SC-008**: The project's quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Signing in, accounts, and anything that varies by who is reading.
- Administration screens of any kind.
- The read-only JSON API.
- Receiving, storing or serving uploaded files. Lead images are rendered from
  what the catalogue already records; the upload path is a separate feature.
- Search, comments, feeds, sitemaps and social preview metadata. Each is a
  reasonable next feature and none is required to read the site.
- Caching, whether HTTP-level or application-level.

## Assumptions

- **Content markup is rendered as authored.** Feature 001 stores body text as
  supplied and states that sanitising belongs with the editor that produces it.
  There is no editor yet, so the only author of content is a developer loading
  fixtures. This is recorded as a known risk rather than solved here: the moment
  an administration screen lets somebody paste markup, sanitising becomes that
  feature's obligation, and FR-024 is what it has to satisfy.
- **Twenty articles per listing page**, which is a conventional default and
  cheap to change.
- **The menu shows only standalone pages**, not sections. A site whose menu
  should include sections can have that later; guessing now would produce a menu
  nobody asked for.
- **Dates are shown in a single fixed format and time zone.** Reader-specific
  formatting needs a reader preference, and there are no readers with
  preferences until sign-in exists.
- **The design is deliberately plain** — readable typography, a menu, a listing,
  an article. This feature is judged on the visibility rules and the structure,
  not on visual design, and an elaborate theme would make both harder to review.
- **Lead images are referenced by their stored filename** under a public path.
  Until the upload feature exists, the files those names point at may not be
  present, which is why FR-023 exists.

## Dependencies

- Feature 001, merged: the entities, the repositories and their published scope.
  This feature adds no query that reimplements "published"; it calls what is
  already there. That is the whole argument of
  [ADR 2](../../docs/adr/0002-twig-monolith-with-read-only-api.md).
