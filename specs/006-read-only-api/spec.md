# Feature Specification: Read-only JSON API

**Feature Branch**: `006-read-only-api`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Read-only JSON API exposing published content only. Another program can list and read published articles and pages, and browse them by section and label, in JSON. Nothing can be created, changed or deleted through it, and nothing unpublished is reachable — the same rule the website already keeps, kept by the same code."

## Overview

[ADR 2](../../docs/adr/0002-twig-monolith-with-read-only-api.md) has claimed since
before anything was built that this project serves the same content through Twig
templates and a JSON API, and that this is only true if one domain layer feeds
both:

> The moment a publishing rule lives in a controller, the API returns different
> content from the website, and the divergence is silent.

That claim has been cheap to make so far, because the API exposes nothing. This
feature is where it is tested. If the API needs its own idea of what "published"
means, the architecture the whole project is arranged around was wrong.

The second thing this feature is judged on is that it stays read-only. API
Platform will happily generate create, update and delete operations from the same
configuration that generates reads; a write operation appearing here would give
the internet an unauthenticated way to change content.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reading published content as data (Priority: P1)

Another program asks for the published articles and gets them as JSON, with
enough in each to render a listing, and can then ask for one in full.

**Why this priority**: it is the feature.

**Acceptance Scenarios**:

1. **Given** published articles, **When** the article collection is requested, **Then** they are returned as JSON, newest first, paginated.
2. **Given** a published article, **When** it is requested by its address, **Then** its title, summary, body, publication date, author display name, section and labels are returned.
3. **Given** published pages, **When** the page collection is requested, **Then** they are returned as JSON.
4. **Given** the API, **When** its entry point is requested, **Then** it describes what can be read.
5. **Given** an article with a lead image, **When** it is read, **Then** the address at which that image can be fetched is included.

---

### User Story 2 - The API cannot see unpublished work (Priority: P1)

Draft and archived content is absent from every collection and unreachable at
every address, exactly as on the website.

**Why this priority**: this is the same rule feature 002 spent its effort on, and
a second delivery mechanism is a second chance to get it wrong. It is also worse
here: an API is read by programs, which do not notice that something looks like
it should not be there.

**Independent Test**: create draft and archived content, then request every
collection and every address.

**Acceptance Scenarios**:

1. **Given** a draft article, **When** the collection is requested, **Then** it is absent and the reported total does not count it.
2. **Given** a draft article, **When** its address is requested directly, **Then** the response is not-found.
3. **Given** an archived article, **When** its address is requested, **Then** the response is not-found.
4. **Given** a draft page, **When** its address is requested, **Then** the response is not-found.
5. **Given** a draft and an address that never existed, **When** each is requested, **Then** the responses are indistinguishable.
6. **Given** any response, **When** it is inspected, **Then** it contains no field that exists only on unpublished content.
7. **Given** the API and the website, **When** both are asked what is published, **Then** they agree — because both ask the same code.

---

### User Story 3 - Browsing by section and label (Priority: P2)

A program can list sections and labels, and fetch the published articles in each.

**Acceptance Scenarios**:

1. **Given** sections, **When** the section collection is requested, **Then** each is returned with its name, address and description.
2. **Given** a section, **When** its articles are requested, **Then** only its published articles are returned.
3. **Given** labels, **When** the label collection is requested, **Then** each is returned with its name and address.
4. **Given** a section holding only drafts, **When** its articles are requested, **Then** an empty collection is returned rather than not-found.

---

### User Story 4 - Nothing can be written (Priority: P1)

No request can create, change or delete anything.

**Why this priority**: the cost of getting it wrong is total, and the mistake is
a single line of configuration.

**Acceptance Scenarios**:

1. **Given** any collection address, **When** a create is attempted, **Then** it is refused as an unsupported method.
2. **Given** any item address, **When** a change is attempted, **Then** it is refused.
3. **Given** any item address, **When** a delete is attempted, **Then** it is refused.
4. **Given** the API description, **When** it is inspected, **Then** it advertises no operation that writes.
5. **Given** any refused write, **When** the content is checked afterwards, **Then** nothing has changed.

---

### Edge Cases

- Personal data must not leak. An author appears as a display name; their email
  address, roles and stored credential must appear nowhere.
- A request for a page number beyond the end returns an empty collection, not an
  error.
- The API must not expose internal identifiers as the way to fetch content, so
  that addresses stay the stable, readable ones the website already uses.
- A section or label that does not exist is not-found; one that exists but has
  nothing published is empty.

## Requirements *(mandatory)*

### Functional Requirements

**Reading**

- **FR-001**: The API MUST expose published articles as a paginated collection, newest first.
- **FR-002**: The API MUST expose a published article by its address, with title, summary, body, publication date, author display name, section and labels.
- **FR-003**: The API MUST expose published pages as a collection and by address.
- **FR-004**: The API MUST expose sections and labels, and the published articles within each.
- **FR-005**: The API MUST include, for content with a lead image, the address at which the image can be fetched, and its alternative text.
- **FR-006**: The API MUST describe itself at a documented entry point.

**Visibility**

- **FR-007**: No unpublished content MUST appear in any collection, count, or embedded relationship.
- **FR-008**: A request for the address of unpublished content MUST produce the same not-found response as an address that never existed.
- **FR-009**: The rule deciding what is published MUST be the one the website already uses. This feature MUST NOT contain a second definition.

**Read-only**

- **FR-010**: No write operation MUST be exposed, for any resource.
- **FR-011**: A write attempt MUST be refused and MUST change nothing.
- **FR-012**: The API description MUST advertise no write operation.

**Not leaking**

- **FR-013**: An author MUST appear only as a display name. Email addresses, roles and credentials MUST NOT appear in any response.
- **FR-014**: Internal numeric identifiers MUST NOT be the addressing scheme.
- **FR-015**: No response MUST include a field that exists only on unpublished content.

**Evidence**

- **FR-016**: Every exposed address MUST have a test proving unpublished content is unreachable through it.
- **FR-017**: Every resource MUST have a test proving each write method is refused.
- **FR-018**: A test MUST assert that no response contains an email address or a role.

### Key Entities

No new entities. This is a second way of reading what already exists.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of unpublished content is absent from every collection and unreachable at every address.
- **SC-002**: Every write method on every exposed address is refused, verified per resource.
- **SC-003**: No API response contains an email address, a role or a password hash.
- **SC-004**: The API and the website return the same set of published content, verified by a test that compares them.
- **SC-005**: The API needs no query that reimplements "published" — verified by the code containing none.
- **SC-006**: The existing suite continues to pass unchanged.
- **SC-007**: The quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Writing anything. That is the point.
- Authentication, keys and rate limiting. The API exposes exactly what the public
  website exposes, so it needs no more protection than the website has — and it
  has none either. Recorded so the absence is a decision rather than an omission.
- Search, filtering beyond section and label, and sorting other than newest
  first.
- Webhooks, subscriptions, GraphQL and versioning.
- Caching headers tuned for a CDN.

## Assumptions

- **Content is addressed by its slug**, as on the website, rather than by a
  numeric identifier. It keeps the two addressing schemes aligned and avoids
  publishing internal identifiers.
- **The body is returned as stored**, which is sanitised markup. A consumer
  rendering it is receiving what a reader of the website receives.
- **Twenty items per page**, matching the website.
- **No authentication.** Everything readable here is already readable without
  signing in, so requiring a key would protect nothing while suggesting it did.

## Dependencies

- Feature 001: the entities and the repositories whose published scope this
  feature must reuse rather than reimplement.
- Feature 002: the website, whose behaviour the API must agree with.
- Feature 005: the media addresses this feature reports.
