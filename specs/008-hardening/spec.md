---

description: "Specification for feature 008 — hardening the sign-in form and the delivered response"
---

# Feature Specification: Hardening

**Feature Branch**: `008-hardening`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The two entries `docs/status.md` has carried in bold since feature 003 — nothing counts how many times somebody tries a password, and nothing constrains what a browser will execute inside a page this CMS serves.

## Why this feature exists

Every feature since 003 has added something behind the sign-in form. Nothing has
yet made the sign-in form itself hard to attack, and `docs/status.md` says so in
bold precisely because "the administration area is closed" invites the assumption
that it is also guarded.

The second half is the other side of feature 004. Sanitising decides what is
stored; a content security policy decides what a browser will run if something
hostile is stored anyway. ADR 10 said in as many words that sanitising is one
layer and a policy belongs on top of it rather than instead of it. This is that
layer, and it is worth having exactly because the first layer might be wrong.

Neither half changes anything a reader or an editor sees when nothing is wrong.
That is the point: a hardening feature whose success criteria are all about
normal use continuing unchanged.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Guessing a password stops being free (Priority: P1)

Somebody who is not an editor tries passwords against the sign-in form. After a
few wrong answers the form stops answering, for a while, and says so.

**Why this priority**: It is the only story here that closes an open door. The
others reduce the damage of a door that was already forced.

**Independent Test**: Submit wrong credentials repeatedly from one client and
observe that the refusals become a refusal to try; then submit the *correct*
credentials from a different client and observe that they still work.

**Acceptance Scenarios**:

1. **Given** a fresh client, **When** it submits wrong credentials fewer times
   than the limit, **Then** each attempt is refused the way it always was
2. **Given** a client that has exhausted the limit, **When** it submits
   credentials — right or wrong — **Then** it is refused without the password
   being checked, and told it tried too often
3. **Given** a client that has exhausted the limit for one handle, **When** a
   different client signs in successfully as anybody, **Then** it is unaffected
4. **Given** a client that has exhausted the limit, **When** enough time passes,
   **Then** it may try again

### User Story 2 - A hostile script does not run (Priority: P1)

An article body contains markup that survived sanitising, or a template gains a
mistake. The browser refuses to execute it.

**Why this priority**: Same priority as US1 and deliberately so — they protect
against different failures and neither substitutes for the other.

**Independent Test**: Request any public page and read the policy header; then
store a script through a path that does not sanitise (the fixtures, the
database) and confirm the delivered page carries a policy that would not run it.

**Acceptance Scenarios**:

1. **Given** any response the application produces, **When** it is delivered,
   **Then** it carries a content security policy
2. **Given** a page containing a script tag in stored content, **When** it is
   delivered, **Then** the policy names no source that would allow that script
   to execute
3. **Given** the site's own scripts and styles, **When** a page is delivered,
   **Then** they load — the policy allows what the application ships and nothing
   else
4. **Given** an administration screen, **When** it is delivered, **Then** it
   works as fully as it did before the policy existed

### User Story 3 - A page cannot be framed or leaked into (Priority: P2)

The remaining headers that cost nothing and close a category of attack each:
clickjacking, referrer leakage, type sniffing.

**Why this priority**: Real, cheap, and none of them is the reason this feature
exists.

**Independent Test**: Read the headers on a public page and on an administration
page and compare them against the list.

**Acceptance Scenarios**:

1. **Given** any response, **When** it is delivered, **Then** it refuses to be
   framed by another site
2. **Given** any response, **When** it is delivered, **Then** it declares that
   its declared content type is to be trusted rather than guessed
3. **Given** a reader following a link away from the site, **When** the browser
   sends a referrer, **Then** it sends the origin rather than the full address

### Edge Cases

- **A signed-in editor is not a suspect.** Throttling counts attempts at the
  sign-in form. It must not affect anybody already signed in.
- **The limit is per client and per handle, not global.** A global counter is a
  denial-of-service tool: one attacker locks out every editor.
- **The policy must not be a lie.** A policy that names sources the application
  does not use, or that is loose enough to allow anything, is worse than none —
  it reports as present in an audit while protecting nothing.
- **Inline scripts exist and are not all ours.** The asset importmap emits one,
  and the generic administration screens emit several. A policy that forbids
  inline scripts outright breaks the administration area; a policy that allows
  them all defeats itself. Each must be identified per response.
- **The test environment must be able to exhaust the limit** without waiting for
  real time to pass, or the requirement cannot be proven.
- **A rate limiter needs storage.** Whatever it is, restarting the application
  must not hand an attacker a fresh allowance on demand.

## Requirements *(mandatory)*

### Functional Requirements

**Sign-in throttling**

- **FR-001**: The sign-in form MUST refuse to check credentials from a client
  that has made more than a fixed number of failed attempts within a fixed window
- **FR-002**: The count MUST be kept per client address *and* per submitted
  handle, so that attacking one account cannot lock out another
- **FR-003**: A refusal for exceeding the limit MUST be distinguishable by the
  person from a refusal for wrong credentials, and MUST NOT reveal whether the
  handle exists
- **FR-004**: A successful sign-in MUST reset that client's count
- **FR-005**: The limit MUST NOT apply to anybody already signed in
- **FR-006**: The window MUST expire, so that a locked-out person recovers
  without administrative action

**Content security policy**

- **FR-007**: Every response the application produces MUST carry a content
  security policy
- **FR-008**: The policy MUST NOT permit inline script execution generically —
  no `unsafe-inline` for scripts
- **FR-009**: Each inline script the application itself emits MUST be marked with
  a value that changes per response, and the policy MUST name that same value
- **FR-010**: The policy MUST restrict the addresses scripts, styles, images,
  fonts and form submissions may reach to the site's own origin, plus whatever
  the application demonstrably needs
- **FR-011**: The policy MUST forbid the page being framed by anybody
- **FR-012**: The policy MUST NOT break any existing screen — public or
  administration — and the test suite MUST demonstrate that per screen rather
  than by assertion

**Other headers**

- **FR-013**: Every response MUST declare that its content type is not to be
  sniffed
- **FR-014**: Every response MUST set a referrer policy that sends no more than
  the origin to another site
- **FR-015**: Responses MUST NOT advertise the server software or framework
  version beyond what the runtime forces

**Scope**

- **FR-016**: This feature MUST NOT change any screen, route, entity, repository
  or service that exists today, beyond adding headers and one refusal
- **FR-017**: The measures MUST be verifiable from the outside — a functional
  test reading a response, not a unit test asserting a configuration value

### Key Entities

None. This feature adds no state to the domain. The rate limiter's counters are
infrastructure, not content, and no entity, repository or migration is touched.

## Success Criteria *(mandatory)*

- **SC-001**: Guessing a password more than a handful of times in a row stops
  working for a period, and the person is told why
- **SC-002**: A locked-out person recovers by waiting, without asking anybody
- **SC-003**: One person being locked out never prevents another from signing in
- **SC-004**: A script stored in content by any route does not execute in a
  reader's browser
- **SC-005**: Every screen that worked before this feature works after it,
  demonstrated per screen
- **SC-006**: Every response carries the full set of headers, demonstrated on a
  public page and an administration page
- **SC-007**: `composer qa` passes and the whole suite grows

## Assumptions

- **Five attempts in a fifteen-minute window** is the limit, and the window is
  what expires. Chosen because it is the shape Symfony's own throttling uses and
  because a smaller number frustrates a person who genuinely mistyped twice.
- **The counter is stored in the cache.** This is a single-node CMS; a cache pool
  is what exists and it survives a request. Recorded as a limitation rather than
  presented as durable storage.
- **The policy is enforced, not report-only.** A report-only policy with nowhere
  to send reports is a comment. If a screen breaks, that is a defect to fix, and
  finding it is the point of FR-012.
- **`unsafe-inline` remains for styles.** The generic administration screens emit
  inline style attributes we do not control, and a nonce cannot mark an
  attribute. Recorded openly rather than hidden: styles can deface, scripts can
  steal, and the two are not the same risk. Revisited if the screens stop
  needing it.
