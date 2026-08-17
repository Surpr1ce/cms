---

description: "Specification for feature 013 — getting back in, and changing a password on purpose"
---

# Feature Specification: Account Recovery

**Feature Branch**: `013-account-recovery`
**Created**: 2026-08-17
**Status**: Draft
**Input**: The `docs/status.md` row "Registration, password reset, password change, email — not started", minus registration, which this CMS deliberately does not want.

## Why this feature exists

There is exactly one way into this CMS and no way back.

An editor who forgets their password has to find an administrator. An
administrator who forgets theirs has to find somebody with a shell on the server
and the confidence to run `app:create-administrator`. On a one-administrator
installation — the shape this CMS is built for — forgetting the password means
the site can no longer be administered at all.

And an editor who suspects their password has been seen cannot change it. They
can ask an administrator to set a new one, which means telling somebody else what
it is going to be, which is worse than the problem.

Both are the same gap: nothing in this application lets somebody prove who they
are by any means other than the password they have lost.

**Registration is not part of this.** A CMS is not a service people sign up to;
accounts are created by an administrator, and a public registration form would be
a way to fill the database with strangers. That decision stays.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Somebody who has forgotten their password gets back in (Priority: P1)

They ask for a link, receive it by email, choose a new password, and are signed
in.

**Why this priority**: It is the feature, and it is the difference between a
forgotten password being an inconvenience and being the end of the installation.

**Independent Test**: Ask for a link for a real account, read the message that
was sent, follow the link, set a password, and sign in with it.

**Acceptance Scenarios**:

1. **Given** an account, **When** its address asks for a reset, **Then** a
   message is sent to that address carrying a link
2. **Given** that link, **When** it is opened, **Then** a form to choose a new
   password is shown
3. **Given** a new password, **When** it is submitted, **Then** it replaces the
   old one and the old one no longer works
4. **Given** a completed reset, **When** the same link is opened again, **Then**
   it is refused — one link, one use
5. **Given** a link older than its lifetime, **When** it is opened, **Then** it
   is refused

### User Story 2 - The form tells a stranger nothing (Priority: P1)

Somebody who does not hold the account learns nothing at all — not whether the
address exists, not whether a message was sent.

**Why this priority**: Equal to US1, because a reset form is the easiest place in
any application to build a list of real email addresses.

**Independent Test**: Ask for a reset for an address that holds an account and
for one that does not, and compare everything about the two responses.

**Acceptance Scenarios**:

1. **Given** an address with no account, **When** a reset is asked for, **Then**
   the response is identical to the one for an address that has one
2. **Given** an address with no account, **When** a reset is asked for, **Then**
   no message is sent to it
3. **Given** repeated requests for one address, **When** they exceed a limit,
   **Then** they are refused — a reset form that sends a message per request is a
   way to use this CMS to send mail to somebody else
4. **Given** a stolen link, **When** anything about it is altered, **Then** it is
   refused

### User Story 3 - Somebody signed in changes their own password (Priority: P2)

Without involving anybody else, and without a reset link.

**Why this priority**: Real and expected, but it is not the door being closed.

**Independent Test**: Sign in, change the password, sign out, sign in with the
new one.

**Acceptance Scenarios**:

1. **Given** somebody signed in, **When** they open their account page, **Then**
   they can change their password
2. **Given** a change, **When** it is submitted, **Then** the current password
   must be given as well — a session left open must not be enough to take an
   account over
3. **Given** a change, **When** the current password is wrong, **Then** it is
   refused and nothing is stored
4. **Given** a completed change, **When** the old password is tried, **Then** it
   no longer works

### Edge Cases

- **A reset link is a credential.** It must be unguessable, it must not be
  readable from the database if the database is read, and it must not survive
  being used.
- **Changing a password must end other sessions**, or somebody who took the
  account keeps it. This CMS has no session registry, so the honest answer is a
  recorded limitation rather than a claim.
- **An account with no password yet** — created by an administrator and never
  used — must be able to receive a reset link, since that is the only way it will
  ever be usable.
- **A reset for a deleted account** must not fail loudly; the link is refused
  like any other invalid one.
- **The sign-in throttle from feature 008 must not lock somebody out of their own
  reset**, and a reset must not be a way around the throttle either.
- **Email may not be configured.** A site that cannot send must not appear to
  have sent.

## Requirements *(mandatory)*

### Functional Requirements

**Requesting a reset**

- **FR-001**: The sign-in page MUST offer a way to ask for a password reset
- **FR-002**: A request for an address holding an account MUST send a message to
  it containing a single-use link
- **FR-003**: A request for an address holding no account MUST send nothing
- **FR-004**: The response MUST be identical in both cases — the same page, the
  same wording, the same status
- **FR-005**: Requests MUST be limited per address and per client

**The link**

- **FR-006**: A link MUST carry a value with at least 128 bits of entropy
- **FR-007**: The value MUST NOT be stored as it appears in the link — a stolen
  database must not yield working links
- **FR-008**: A link MUST expire after a fixed period
- **FR-009**: A link MUST be refused after it has been used once
- **FR-010**: Requesting a new link MUST invalidate any earlier one for that
  account
- **FR-011**: An invalid, expired or used link MUST produce the same refusal, and
  MUST NOT say which

**Setting the password**

- **FR-012**: A new password MUST meet the same rules an administrator's screen
  applies
- **FR-013**: A completed reset MUST replace the stored credential and consume
  the link
- **FR-014**: A completed reset MAY sign the person in, and if it does MUST do so
  only after the password has been stored

**Changing a password**

- **FR-015**: Somebody signed in MUST be able to change their own password
- **FR-016**: A change MUST require the current password
- **FR-017**: A refused change MUST store nothing

**Everywhere**

- **FR-018**: No screen or message may reveal whether an address holds an account
- **FR-019**: No response may contain a stored password hash or a stored token
- **FR-020**: Nothing here may weaken feature 008's throttling or feature 003's
  authorisation

### Key Entities

- **PasswordResetRequest** — an account, a hashed token, when it was created, and
  whether it has been used. One live request per account: asking again replaces
  what came before.

## Success Criteria *(mandatory)*

- **SC-001**: A person who has forgotten their password can get back in without
  anybody else's help
- **SC-002**: A stranger cannot learn from this feature whether an address holds
  an account
- **SC-003**: A stolen database yields no working reset links
- **SC-004**: A link works once and then never again
- **SC-005**: Somebody signed in can change their password without anybody
  learning it
- **SC-006**: `composer qa` passes and the whole suite grows

## Assumptions

- **One hour**, which is long enough for somebody to find the message and short
  enough that a link left in an inbox stops being a credential the same day.
- **The token is hashed with SHA-256, not with the password hasher.** It is a
  128-bit random value, not a human-chosen secret: there is nothing to brute
  force, and a deliberately slow hash on every lookup would be a denial-of-service
  surface on an unauthenticated route.
- **Five requests an hour per address**, using the rate limiter feature 008
  already installed.
- **The reset signs the person in**, because the alternative is showing a sign-in
  form to somebody who has just proved they hold the address.
- **Other sessions are not ended.** This CMS keeps no registry of sessions, and
  building one is a feature of its own. Recorded as a limitation, because a
  password change that does not end other sessions protects less than people
  assume.
- **Email delivery is not configured by this feature.** It uses whatever
  `MAILER_DSN` names; the tests assert what was sent, not that it arrived.
