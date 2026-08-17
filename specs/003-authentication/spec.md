# Feature Specification: Authentication and Authorisation

**Feature Branch**: `003-authentication`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Authentication and authorisation: sign-in, roles and ownership-based voters. Somebody with an account can sign in and out; the administration area is closed to everybody else; and what a signed-in person may do depends both on their role and on whether the content is theirs. An author may edit their own drafts and may not publish; an editor may edit and publish anything and manage taxonomy and media; an administrator may do everything including managing accounts. No administration screens in this feature — the decisions, not the interfaces."

## Overview

Feature 002 put the site on the public internet with no way in. This feature adds
the door and the lock, and nothing behind it: sign-in, sign-out, and the rules
that decide what a signed-in person may do.

Deliberately no screens. The reason is that authorisation rules are the part that
must be right, and building them alongside forms makes it tempting to test the
rule by clicking through the form — which tests the form. Here the rules are
tested directly, and the administration feature that follows consumes them.

The distinction that makes this non-trivial is ownership. "May this person edit
this article" cannot be answered from their role alone: an author may edit *their
own* drafts and nobody else's. That is why the answer lives in a voter rather
than in a role check, and it is the reason
[`docs/domain-model.md`](../../docs/domain-model.md) has said so since before any
of it was built.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Signing in and out (Priority: P1)

Somebody with an account enters their email address and password and is
recognised. When they are finished they sign out, and the session no longer
recognises them.

**Why this priority**: nothing else in this feature or the next can be reached
without it.

**Independent Test**: submit correct credentials and confirm recognition; submit
wrong ones and confirm refusal; sign out and confirm the recognition is gone.

**Acceptance Scenarios**:

1. **Given** an account with a known password, **When** that email and password are submitted, **Then** the person is signed in and sent to the administration area.
2. **Given** an account, **When** the right email and a wrong password are submitted, **Then** sign-in is refused and the person stays on the sign-in page.
3. **Given** an email address that no account uses, **When** it is submitted with any password, **Then** sign-in is refused with the same message and in a similar time as a wrong password would produce.
4. **Given** a signed-in person, **When** they sign out, **Then** the session no longer recognises them and a protected address sends them back to sign-in.
5. **Given** a signed-in person, **When** they open the sign-in page, **Then** they are sent to the administration area rather than shown the form again.
6. **Given** anybody at all, **When** the sign-in page is opened, **Then** it renders in the site's own layout.

### Edge Cases for User Story 1

- A wrong password and an unknown email address must be indistinguishable, in
  wording and in timing. Otherwise the form becomes a way to discover which
  email addresses hold accounts.
- An account whose stored credential is empty — which is the state a newly
  created account is in — must never be able to sign in, whatever is submitted.
- A submitted form without the expected one-time token must be refused, so that
  another site cannot cause a sign-in on somebody's behalf.

---

### User Story 2 - The administration area is closed (Priority: P1)

Every address under the administration area requires a signed-in person. Somebody
who is not signed in is sent to sign in; somebody who is signed in but lacks the
role is refused.

**Why this priority**: this is the rule whose failure is unrecoverable. A public
route that leaks a draft is bad; an administration route reachable without
signing in is worse.

**Independent Test**: request a protected address anonymously, and again as each
role, and confirm each outcome.

**Acceptance Scenarios**:

1. **Given** nobody signed in, **When** an administration address is requested, **Then** the response redirects to the sign-in page rather than showing the content.
2. **Given** nobody signed in, **When** an administration address is requested, **Then** nothing about what is behind it appears in the response.
3. **Given** a signed-in person with an insufficient role, **When** they request an address their role does not permit, **Then** they are refused rather than redirected to sign in — they are recognised, just not permitted.
4. **Given** a signed-in person, **When** they are sent to sign in after their session ends, **Then** they return to where they were going once they have signed in.
5. **Given** the public site, **When** any of its addresses is requested anonymously, **Then** it is unaffected by any of the above.

---

### User Story 3 - An author may work on their own drafts (Priority: P1)

Somebody with the author role may create articles, and may edit and delete their
own while those are drafts. They may not touch anybody else's work, and they may
not publish anything at all — including their own.

**Why this priority**: it is the case that makes roles insufficient on their own,
and therefore the case that decides the shape of the whole feature.

**Independent Test**: create two authors and content belonging to each, then ask
the permission question for every combination.

**Acceptance Scenarios**:

1. **Given** an author and their own draft article, **When** permission to edit it is asked, **Then** it is granted.
2. **Given** an author and another author's draft, **When** permission to edit it is asked, **Then** it is refused.
3. **Given** an author and their own draft, **When** permission to publish it is asked, **Then** it is refused.
4. **Given** an author and their own article that has been published, **When** permission to edit it is asked, **Then** it is refused — it is no longer theirs to change alone.
5. **Given** an author, **When** permission to create an article is asked, **Then** it is granted.
6. **Given** an author and their own draft, **When** permission to delete it is asked, **Then** it is granted.
7. **Given** an author and another author's article in any state, **When** permission to delete it is asked, **Then** it is refused.

---

### User Story 4 - An editor is responsible for what is published (Priority: P2)

Somebody with the editor role may edit, publish, unpublish, archive and restore
any content, whoever wrote it, and may manage sections, labels and files. They may
not manage accounts.

**Why this priority**: it is the role the site is actually run by day to day, but
it is meaningless without User Story 3 to be an exception to.

**Independent Test**: ask every permission question as an editor against content
belonging to somebody else.

**Acceptance Scenarios**:

1. **Given** an editor and any article, **When** permission to edit it is asked, **Then** it is granted whoever wrote it and whatever its state.
2. **Given** an editor and any article, **When** permission to publish it is asked, **Then** it is granted.
3. **Given** an editor, **When** permission to manage sections, labels or files is asked, **Then** it is granted.
4. **Given** an editor, **When** permission to manage accounts is asked, **Then** it is refused.
5. **Given** an editor and a standalone page, **When** permission to edit or publish it is asked, **Then** it is granted — a page has no author, so ownership cannot apply.

---

### User Story 5 - An administrator may manage accounts (Priority: P2)

Somebody with the administrator role may do everything an editor may, and may in
addition create, edit and delete accounts and change what roles they hold.

**Why this priority**: needed before a second person can be given access, and not
before.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** permission to manage accounts is asked, **Then** it is granted.
2. **Given** an administrator, **When** any permission an editor holds is asked, **Then** it is granted.
3. **Given** an administrator and their own account, **When** permission to delete it is asked, **Then** it is refused — an administrator must not be able to lock everybody out by removing themselves.
4. **Given** an administrator and another account, **When** permission to delete it is asked, **Then** it is granted, subject to the ownership rule the content model already enforces.

---

### Edge Cases

- An account holding no roles at all must be able to sign in and do nothing.
- An account holding an unrecognised role string must not gain anything from it.
- A permission question about content that does not exist must be refused rather
  than producing an error.
- Roles do not accumulate implicitly: holding the editor role does not make
  somebody an administrator, and the rules that grant an editor's permissions to
  an administrator must be explicit and testable.
- Signing in must not be possible for an account whose stored credential is
  empty, whatever is submitted.
- A person whose role is reduced while signed in must lose the permission at the
  next request, not at the next sign-in.

## Requirements *(mandatory)*

### Functional Requirements

**Signing in**

- **FR-001**: The system MUST let somebody sign in with the email address and password of an existing account.
- **FR-002**: The system MUST refuse a wrong password, an unknown email address, and an account with no stored credential, and MUST NOT let the response distinguish which occurred.
- **FR-003**: The system MUST verify the password against the stored hash and MUST NOT store, log or display a password in readable form at any point.
- **FR-004**: The system MUST refuse a sign-in submission that does not carry the expected one-time token.
- **FR-005**: The system MUST let a signed-in person sign out, after which the session no longer recognises them.
- **FR-006**: The system MUST send a signed-in person away from the sign-in page rather than showing it again.
- **FR-007**: After signing in, the system MUST return the person to the address they were trying to reach, or to the administration area if there was none.

**Closing the administration area**

- **FR-008**: Every address under the administration area MUST require a signed-in person.
- **FR-009**: An anonymous request for a protected address MUST redirect to the sign-in page and MUST disclose nothing about what is behind it.
- **FR-010**: A signed-in person lacking the required role MUST be refused, and MUST NOT be redirected to sign in.
- **FR-011**: Public addresses MUST remain reachable without signing in.

**Deciding what somebody may do**

- **FR-012**: The system MUST answer permission questions about a specific piece of content, not only about a role.
- **FR-013**: An author MUST be permitted to create articles, and to edit and delete their own articles while those are drafts.
- **FR-014**: An author MUST be refused edit and delete permission on content they do not own, in every state.
- **FR-015**: An author MUST be refused permission to change the publication state of any content, including their own.
- **FR-016**: An author MUST be refused edit permission on their own content once it has been published.
- **FR-017**: An editor MUST be permitted to edit, delete and change the publication state of any content, and to manage sections, labels and files.
- **FR-018**: An editor MUST be refused permission to manage accounts.
- **FR-019**: An administrator MUST be permitted everything an editor is, and additionally to manage accounts.
- **FR-020**: An administrator MUST be refused permission to delete their own account.
- **FR-021**: An account with no roles, or with an unrecognised role, MUST be granted nothing.
- **FR-022**: Standalone pages MUST be governed by role alone, because they have no author for ownership to refer to.
- **FR-023**: A permission question about content that does not exist MUST be refused rather than raising an error.
- **FR-024**: A change to somebody's roles MUST take effect on their next request rather than at their next sign-in.

  > **Stands, after nearly being weakened.** The first two attempts to test this
  > failed, and the requirement was briefly amended to the weaker "no later than
  > their next sign-in" on the grounds that a security property which cannot be
  > demonstrated must not be claimed. Both attempts were wrong about the test,
  > not the code: the kernel is rebooted between requests, so the account object
  > being modified belonged to an entity manager that no longer existed and the
  > flush wrote nothing. Reloading the account through the current manager shows
  > the role revocation taking effect on the very next request, as Symfony's
  > `ContextListener` is written to do. The original requirement is restored and
  > the episode is left recorded here, because a test that passes while proving
  > nothing is worse than one that fails.

**Evidence**

- **FR-025**: Every protected address MUST have a test for the anonymous case and a test for the insufficient-role case.
- **FR-026**: Every permission question MUST have a test for each role, and every ownership rule MUST have a test proving the not-the-owner case is refused.
- **FR-027**: The system MUST provide a way to give an account a password without a screen, so that a first administrator can exist before any administration interface does.

### Key Entities

No new entities. `User` already carries the email address, the stored credential
and the roles; feature 001 built it implementing the interfaces this feature
finally uses.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of administration addresses are unreachable without signing in, verified by a test per address.
- **SC-002**: A wrong password and an unknown email address produce responses that are identical in wording and status.
- **SC-003**: Every combination of role and ownership in User Stories 3 to 5 has a test — granted cases and refused cases alike.
- **SC-004**: No password appears in readable form in any log, response or exception, under any input.
- **SC-005**: A person can be given administrative access on a fresh installation with no administration interface present.
- **SC-006**: The public site behaves exactly as it did before this feature, verified by the existing functional suite continuing to pass unchanged.
- **SC-007**: The project's quality gate passes with no rule relaxed, no suppression added and no test skipped.

## Out of Scope

- Every administration screen. This feature provides the decisions; the next one
  provides the interfaces that ask them.
- Registration, password reset, password change, and email of any kind.
- Two-factor authentication, "remember me", session expiry policy, and rate
  limiting on the sign-in form. Each is a reasonable next step and none is needed
  to close the door.
- Per-section or per-label permissions.
- An audit log of who did what.

## Assumptions

- **One sign-in form, at a fixed address**, rather than a mechanism that varies
  by role. There is one kind of account.
- **Sessions are the standard server-side kind.** There is no API client to
  authenticate; the read-only API is public.
- **The three roles are those already in the model** — administrator, editor,
  author — plus the baseline every signed-in person holds. No fourth role is
  invented here.
- **An administrator is granted an editor's permissions explicitly rather than by
  inheritance**, so that each grant is visible and testable rather than implied
  by configuration.
- **A first administrator is created from the console.** Something has to bootstrap
  access before an interface exists, and a console command is the smallest thing
  that can.
- **Rate limiting is out of scope but the timing requirement is not.** Refusals
  must not differ in time enough to distinguish an unknown address from a wrong
  password; that is a property of how the check is written, not of a throttle.

## Dependencies

- Feature 001: `User` with its roles and stored credential, already implementing
  `UserInterface` and `PasswordAuthenticatedUserInterface`.
- Feature 002: the site layout the sign-in page renders inside, and the existing
  functional suite that proves the public site is unaffected.
