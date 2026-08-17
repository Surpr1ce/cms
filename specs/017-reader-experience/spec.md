---

description: "Specification for feature 017 — a public site a reader can move around in"
---

# Feature Specification: Reader Experience

**Feature Branch**: `017-reader-experience`
**Created**: 2026-08-17
**Status**: Draft
**Input**: "And the frontend for users — can't it be done better? I think a lot is missing there."

## Why this feature exists

Sixteen features had produced a public site that answered on every address and
gave a reader almost nothing to do.

**No listing showed a picture.** Every article in the fixtures has a lead image,
the CMS stores three sizes of each, feature 012 built the whole derived-image
machinery — and the only place any of it appeared was inside an article. A site
whose entire purpose is publishing pictures and words was showing only the words.

**The site's own structure was invisible from the site.** The header carried
standalone pages and nothing else. Sections — the thing every article is filed
under — could be reached only by noticing a small grey link beneath an article's
title. A reader who wanted "more like this" had to guess that such a page existed.

**An article ended and that was it.** No related articles, nothing before or
after, no way onward except the browser's back button. That is the single largest
thing a content site can do to be read more, and it was absent.

**Nobody knew where they were.** Somebody arriving from a search engine lands
three levels in, on a page with a title and no context.

**The footer was the site's name.** No feed link, no sections, no pages, no way
in for an editor.

None of this is a defect in the sense the earlier features used the word. Every
rule held, every test passed, every address answered. It was simply a site nobody
would stay on — and, like features 015 and 016, it took looking at it to see.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A listing shows what the articles are (Priority: P1)

Pictures, and how long each piece takes to read.

**Independent Test**: Open the front page and look at it.

**Acceptance Scenarios**:

1. **Given** an article with a lead image, **When** it appears in any listing,
   **Then** the image is shown at the size the listing displays it
2. **Given** an article without one, **When** it appears, **Then** the card
   renders without a gap where a picture would be
3. **Given** any article in a listing, **When** it is shown, **Then** it says
   roughly how long it takes to read

### User Story 2 - A reader can move around (Priority: P1)

The sections are reachable from anywhere; every page says where the reader is;
the footer offers the rest.

**Independent Test**: Land on an article from outside and try to reach the front
page, its section, and the feed without using the back button.

**Acceptance Scenarios**:

1. **Given** any page, **When** it is rendered, **Then** the sections appear in
   the site navigation
2. **Given** any content page, **When** it is rendered, **Then** a trail names
   where it sits and links back
3. **Given** an article in a section, **When** its trail is read, **Then** the
   section is named and linked
4. **Given** a page whose parent is a draft, **When** its trail is read, **Then**
   the draft is not named — a trail must not disclose what a listing would not
5. **Given** any page, **When** the footer is read, **Then** it offers the feed,
   the sections, the pages and a way to sign in
6. **Given** somebody using a keyboard, **When** they arrive, **Then** they can
   skip the header in one press

### User Story 3 - An article leads somewhere (Priority: P1)

More like this, and the ones either side of it.

**Independent Test**: Read to the bottom of an article and see what is offered.

**Acceptance Scenarios**:

1. **Given** an article in a section with others, **When** it is read to the
   bottom, **Then** related articles are offered
2. **Given** an article sharing labels with others, **Then** those count as
   related, and sharing more counts for more
3. **Given** an article with published articles either side of it by date,
   **Then** both are offered
4. **Given** an article related to nothing, **Then** nothing is claimed to be
   related — no falling back to "most recent"
5. **Given** anything unpublished, **Then** it is never suggested anywhere

### Edge Cases

- **A suggestion is a disclosure if it is wrong.** Anything offered as "read
  next" must come from a query that cannot return unpublished content.
- **A trail built from `.parent` can name a draft.** Pages already had a
  published-ancestors list for exactly this reason and it must keep being used.
- **A listing must not send full-size images.** Twelve articles at four thousand
  pixels each to render them at a hundred and sixty is the cost feature 012
  existed to remove.
- **Reading time must never be zero.** "0 min" reads as a fault.

## Requirements *(mandatory)*

- **FR-001**: A listing MUST show an article's lead image at the derived size it
  displays, and MUST render without one when there is none
- **FR-002**: A listing and an article MUST state an estimated reading time, never
  below one minute
- **FR-003**: The sections MUST be reachable from the navigation on every page
- **FR-004**: Every content page MUST show a trail naming where it sits
- **FR-005**: A trail MUST NOT name anything a reader could not open
- **FR-006**: The footer MUST offer the feed, the sections, the pages and the way
  in
- **FR-007**: Every page MUST offer a way to skip the header
- **FR-008**: An article MUST offer related published articles — same section or
  shared labels, most shared first
- **FR-009**: An article MUST offer the published articles either side of it by
  date
- **FR-010**: An article related to nothing MUST offer no related articles
- **FR-011**: Nothing unpublished may be suggested by any of it
- **FR-012**: A search result page MUST say how many results it is showing

## Success Criteria *(mandatory)*

- **SC-001**: A reader landing on an article can reach the front page, its
  section, another article and the feed without the back button
- **SC-002**: A listing looks like a publication rather than a list of links
- **SC-003**: No suggestion anywhere leads to something a reader cannot open
- **SC-004**: `composer qa` passes and the whole suite grows

## Assumptions

- **Related means same section or shared labels**, ordered by how much is shared.
  Anything cleverer is a recommendation engine, and a CMS that guesses is a CMS
  that guesses wrong in public.
- **Three related articles**, and top-level sections only in the navigation. A
  header listing forty sections is a header nobody reads.
- **Two hundred words a minute** — the low end of the usual estimates. A reader
  who finishes early is pleased; one who runs over feels lied to.
- **No author pages.** Crediting an author with a name that links nowhere is a
  real gap, and giving accounts public addresses is a decision with its own
  privacy question. Recorded rather than done.
- **No dark mode**, no share buttons, no comments. Each is a feature, not a
  detail of this one.
