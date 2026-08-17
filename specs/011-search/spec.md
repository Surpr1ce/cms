---

description: "Specification for feature 011 — finding an article by what it says"
---

# Feature Specification: Search

**Feature Branch**: `011-search`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The `docs/status.md` row feature 010 left behind: "Search — not started. A reader who knows a word from an article still has no way to find it. The largest remaining gap in the public site."

## Why this feature exists

Feature 010 made the site findable from outside. Inside it, a reader who
remembers a phrase from something they read here has exactly two options: page
through the archive twenty articles at a time, or leave and ask a search engine
to do what this site will not.

The second is what actually happens, and it is worse than it sounds — it means
the site's own archive is only usable through somebody else's index, which is
always out of date and covers only what has been crawled.

This is the last of the reader-facing gaps. After it, everything remaining on the
"not done" list is either an operator concern or an optimisation.

The rule that has governed every delivery since feature 002 governs this one
too, and here it is easiest to get wrong: **a search must not find what a reader
may not read.** A query is an oracle. Given a search box that matches
unpublished work, anybody can ask "does an article containing the word
*acquisition* exist" and be answered, without ever seeing the article.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A reader finds an article by a word in it (Priority: P1)

Somebody types a word or phrase and gets the published articles and pages that
contain it, most relevant first.

**Why this priority**: It is the feature.

**Independent Test**: Publish articles with known words, search for one, and
compare the results against what contains it.

**Acceptance Scenarios**:

1. **Given** a published article whose body contains a word, **When** that word
   is searched for, **Then** the article is in the results
2. **Given** a published article whose *title* contains the word, **When** it is
   searched for, **Then** it ranks above one that merely mentions it in passing
3. **Given** published pages as well as articles, **When** a word both contain is
   searched for, **Then** both kinds appear, each labelled as what it is
4. **Given** a word no content contains, **When** it is searched for, **Then**
   the reader is told plainly that nothing matched — not shown an empty page and
   not shown an error
5. **Given** more results than fit on a page, **When** they are listed, **Then**
   they page the way every other listing on this site pages

### User Story 2 - Search cannot be used to discover unpublished work (Priority: P1)

A draft, an archived article and a draft page are invisible to search, exactly as
they are invisible everywhere else.

**Why this priority**: Equal to US1, and the requirement most likely to be broken
by a query written for relevance rather than for visibility.

**Independent Test**: Create a draft containing a word that appears nowhere else,
search for it, and read everything the response contains.

**Acceptance Scenarios**:

1. **Given** a draft containing a distinctive word, **When** it is searched for,
   **Then** it does not appear and the response says nothing matched
2. **Given** an archived article, **When** a word from it is searched for,
   **Then** it does not appear
3. **Given** a draft, **When** a word from it is searched for, **Then** nothing
   in the response — not a count, not a title, not a snippet — reveals that it
   exists
4. **Given** somebody signed in as an editor, **When** they search, **Then** they
   see exactly what an anonymous reader sees; the public search is not an
   administration tool

### User Story 3 - A search box is on every page and behaves (Priority: P2)

The box is where a reader expects it, an empty search is not an error, and a
query containing markup is not an injection.

**Why this priority**: The mechanics that decide whether the feature is usable
rather than merely present.

**Independent Test**: Submit an empty query, a very long one, and one made of
markup, and read what comes back.

**Acceptance Scenarios**:

1. **Given** any page of the site, **When** a reader looks, **Then** there is a
   search box on it
2. **Given** an empty query, **When** it is submitted, **Then** the search page
   invites a query rather than reporting no results or failing
3. **Given** a query containing markup or quotes, **When** it is submitted,
   **Then** it is treated as words to look for and appears on the page as typed,
   not as markup
4. **Given** a query, **When** results are shown, **Then** the query is still in
   the box so it can be refined
5. **Given** a search results page, **When** a crawler reads it, **Then** it is
   told not to index it — search results are not content

### Edge Cases

- **A one-letter query** matches almost everything and costs the most to answer.
  There is a minimum.
- **An enormous query** must be bounded before it reaches the database.
- **Punctuation, operators and quotes** are words to look for, not syntax. A
  reader typing `AND` means the word.
- **An empty result and an empty query are different things** and must read
  differently.
- **Content with no body yet** — a draft being written — cannot appear anyway,
  but the query must not fall over on it.
- **Search is a public, unauthenticated, unbounded-cost endpoint.** It is the
  cheapest thing on the site to abuse. Rate limiting is out of scope and recorded
  as such, but the query is bounded in length and the results in number.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The site MUST serve a search page that accepts a query and lists
  matching published articles and pages
- **FR-002**: Results MUST be ordered by relevance, with a match in a title
  counting for more than a match in a body
- **FR-003**: Results MUST NOT include any content a reader could not open
  directly — no drafts, no archived content
- **FR-004**: A response for a query that matches nothing MUST say so, and MUST
  be identical whether or not unpublished content would have matched
- **FR-005**: Results MUST be paged, using the same page size and the same
  controls as every other listing
- **FR-006**: Each result MUST show what it is — an article or a page — its
  title, and enough text to judge it by
- **FR-007**: A query MUST be treated as words to look for. No part of it may be
  interpreted as query syntax, and none of it may reach the database as anything
  but a bound value
- **FR-008**: A query MUST be shortened to a maximum length before it is used
- **FR-009**: A query shorter than a minimum MUST be refused with an invitation
  rather than an error
- **FR-010**: An empty query MUST render the search page with an invitation, not
  an empty result list
- **FR-011**: The query MUST be redisplayed exactly as typed, escaped, in both
  the box and any message about it
- **FR-012**: A search box MUST appear on every public page
- **FR-013**: The results page MUST tell crawlers not to index it
- **FR-014**: The search MUST behave identically for an anonymous reader and a
  signed-in one

### Key Entities

None. Search adds no state. It is another reading of content that already
exists — the fourth delivery of the same domain, after the website, the API and
the feed.

## Success Criteria *(mandatory)*

- **SC-001**: A reader who remembers a phrase from an article can reach it
  without leaving the site
- **SC-002**: A title match outranks a passing mention
- **SC-003**: No query, by any reader, can reveal that unpublished content exists
- **SC-004**: An empty query, a one-letter query and a query made of markup all
  produce a sensible page rather than an error
- **SC-005**: `composer qa` passes and the whole suite grows

## Assumptions

- **PostgreSQL's own full-text search**, not a `LIKE` scan. It stems words, so a
  search for *publishing* finds *published*; a `LIKE` would not, and a reader
  cannot be expected to guess an author's grammar. This is why the stack chose
  PostgreSQL, and using it is cheaper than adding a search engine to a CMS that
  serves one site.
- **English stemming**, matching the language the constitution requires
  everything to be written in. A multilingual site would need a decision this
  feature does not make.
- **Articles and pages, not sections or labels.** Those are listings with names,
  and a reader looking for a section is looking at the menu.
- **Two characters minimum, two hundred maximum.**
- **No snippet highlighting.** Showing where the match occurred is a genuine
  improvement and a separate piece of work; this shows the same summary the rest
  of the site shows, which is honest and already built.
- **No rate limiting**, recorded as a gap. Feature 008 throttles sign-in because
  that protects credentials; throttling a public read is a capacity decision that
  belongs with the caching work still on the list.
