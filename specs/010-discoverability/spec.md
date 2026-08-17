---

description: "Specification for feature 010 — being found and being shared"
---

# Feature Specification: Discoverability

**Feature Branch**: `010-discoverability`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The `docs/status.md` row reading "Search, feeds, sitemap, social preview metadata — not started", minus search, which is its own feature.

## Why this feature exists

Nine features have built a CMS that works and that nobody can find.

A reader who does not already know an address has three ways to arrive: a search
engine that has been told what exists, a feed reader that has been told what is
new, and a link somebody shared that shows what it leads to. This CMS supports
none of them. Every published article is a page a crawler must stumble across, a
change nothing announces, and a link that pastes into any chat window as a bare
address with no title.

None of this is difficult. All of it is the kind of thing that is never done
because it is never urgent, and it stays undone until somebody asks why the site
gets no traffic.

The connecting thread is the one ADR 2 drew: there is one domain and several ways
of delivering it. A sitemap, a feed and a set of preview tags are three more
deliveries of exactly what the website already shows, and — this is the part that
matters — **they must show exactly that and nothing more**. A feed that lists
drafts is a disclosure, not a feature.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A search engine is told what exists (Priority: P1)

A crawler asks for the sitemap and receives every published address, and only
those.

**Why this priority**: It is the one that decides whether anybody arrives at all.

**Independent Test**: Request the sitemap and compare what it lists against what
the site actually serves.

**Acceptance Scenarios**:

1. **Given** published articles, pages, sections and labels, **When** the sitemap
   is requested, **Then** every one of their addresses appears in it
2. **Given** a draft or archived article, **When** the sitemap is requested,
   **Then** its address does not appear
3. **Given** the sitemap, **When** each address in it is requested, **Then**
   every one answers successfully — a sitemap listing a 404 is worse than none
4. **Given** the sitemap, **When** it is requested, **Then** it is served as XML
   and is well formed
5. **Given** a crawler, **When** it asks for the robots file, **Then** it is told
   where the sitemap is and that the administration area is not for it

### User Story 2 - A reader subscribes to what is new (Priority: P1)

A feed reader is given the most recent published articles, newest first, with
enough of each to decide whether to read it.

**Why this priority**: Equal to US1 and for a different audience — the people who
already know the site and want to be told when it changes.

**Independent Test**: Request the feed, parse it, and compare it against the home
page.

**Acceptance Scenarios**:

1. **Given** published articles, **When** the feed is requested, **Then** it
   lists them newest first
2. **Given** a draft or archived article, **When** the feed is requested,
   **Then** it does not appear
3. **Given** the feed, **When** it is parsed, **Then** each entry carries a
   title, an absolute address, a publication date and a summary
4. **Given** an article whose body contains markup, **When** it appears in the
   feed, **Then** the markup does not break the document
5. **Given** the site's pages, **When** any is opened in a browser, **Then** the
   feed is discoverable from it without knowing its address

### User Story 3 - A shared link shows what it leads to (Priority: P2)

Somebody pastes an address into a chat window or a social network and it appears
as a title, a description and, where there is one, an image.

**Why this priority**: Real and visible, but it changes how an arrival looks
rather than whether it happens.

**Independent Test**: Read the head of an article page and check the preview tags
against what the page actually contains.

**Acceptance Scenarios**:

1. **Given** a published article, **When** its page is delivered, **Then** it
   carries a title, a description and an absolute address for previewing
2. **Given** an article with a lead image, **When** its page is delivered,
   **Then** the preview names that image by absolute address
3. **Given** an article without a lead image, **When** its page is delivered,
   **Then** the preview omits the image rather than naming one that does not
   exist
4. **Given** any page of the site, **When** it is delivered, **Then** it declares
   its own canonical address
5. **Given** a description built from an article's body, **When** it is
   delivered, **Then** it contains no markup

### Edge Cases

- **The sitemap must not become a disclosure.** It is generated from the same
  repository methods the website uses, so nothing unpublished can reach it
  without also being readable on the site.
- **A feed is served to nobody in particular**, so it must never contain anything
  that depends on who is asking.
- **Addresses in a feed and a sitemap must be absolute**, because they are read
  somewhere other than the site.
- **A description is not a body.** Markup, newlines and a five-thousand-word
  article all have to become one short line of plain text.
- **A site with no published content** must still answer with a valid, empty
  sitemap and feed rather than an error.
- **The sitemap grows.** A site with fifty thousand articles needs more than one
  document, and the format has a limit. Out of scope, and stated so.

## Requirements *(mandatory)*

### Functional Requirements

**Sitemap**

- **FR-001**: The site MUST serve a sitemap listing every published article,
  page, section and label
- **FR-002**: The sitemap MUST contain no address that is not publicly readable
- **FR-003**: Every address in the sitemap MUST be absolute
- **FR-004**: The sitemap MUST carry, for each address, the date its content last
  changed
- **FR-005**: The site MUST serve a robots file naming the sitemap and excluding
  the administration area

**Feed**

- **FR-006**: The site MUST serve a feed of the most recently published articles,
  newest first
- **FR-007**: The feed MUST contain no unpublished content
- **FR-008**: Each entry MUST carry a title, an absolute address, a publication
  date and a summary
- **FR-009**: The feed MUST be well formed whatever the content contains
- **FR-010**: The feed MUST be limited to a fixed number of recent entries
- **FR-011**: Every page of the site MUST advertise the feed in its head

**Preview metadata**

- **FR-012**: Every public page MUST carry a preview title, a description and an
  absolute address
- **FR-013**: An article or page with a lead image MUST name it by absolute
  address; one without MUST omit the image entirely
- **FR-014**: Descriptions MUST be plain text — no markup, no newlines — and MUST
  be shortened to a length a preview will use
- **FR-015**: Every public page MUST declare its canonical address

**Everywhere**

- **FR-016**: Nothing here may query for content by any route that could return
  unpublished work
- **FR-017**: None of it may require the administration area, an account, or any
  configuration a person has to remember to set

### Key Entities

None. This feature adds no state. Everything it serves is derived from content
that already exists.

## Success Criteria *(mandatory)*

- **SC-001**: A search engine can discover every published address without
  following a single link
- **SC-002**: Nothing unpublished appears in the sitemap or the feed, proven the
  same way feature 002 proved it for the website
- **SC-003**: Every address the sitemap lists answers successfully
- **SC-004**: A reader can subscribe to the site from any page of it
- **SC-005**: A shared link shows a title, a description and, where one exists,
  an image
- **SC-006**: `composer qa` passes and the whole suite grows

## Assumptions

- **One sitemap document, not an index.** A single document holds fifty thousand
  addresses, which is far beyond anything this CMS will hold before somebody
  reconsiders. Recorded as a limit rather than engineered around.
- **The feed is Atom.** It is the stricter of the two formats, it requires
  absolute addresses and unambiguous dates, and a reader that understands RSS
  understands it.
- **Twenty entries in the feed**, matching the page size the website already
  uses, so "the front page" and "the feed" mean the same thing.
- **Open Graph tags**, which the major networks and chat clients read, plus the
  ordinary `description` tag. No network-specific dialect beyond that.
- **The site's own address comes from the request** rather than from
  configuration, so nothing has to be remembered when the site moves. FR-017
  exists to keep it that way.
