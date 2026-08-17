# 9. Voters instead of a role hierarchy, and a console command to bootstrap access

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: [specs/003-authentication](../../specs/003-authentication/spec.md)

## Context

Three roles exist in the model: administrator, editor, author. Two questions have
to be answered about them, and they are not the same question.

The first is *where may this person go* — is `/admin` open to them at all. Symfony
answers that with `access_control` and a role check, and that is the right tool.

The second is *what may they do to this particular thing*. An author may edit
their own drafts and nobody else's. No role check can answer that, because the
answer depends on the article as much as on the person.
[`docs/domain-model.md`](../domain-model.md) has said so since before any of this
was built: "the distinction between author and editor is the reason authorisation
needs voters rather than role checks".

That much is settled. What is not settled is how an administrator comes to hold
an editor's permissions, and how anybody signs in at all on a fresh installation
where no interface exists to create an account.

## Decision

**Every permission that depends on a subject is a voter**, in `src/Security/`.
Three of them, split by what the subject is: `ArticleVoter` (has an owner),
`PageVoter` (has no owner), `AdministrationVoter` (has no subject).

**An administrator's permissions are granted explicitly in the voters, not by
`role_hierarchy`.** Where an editor is granted something, an administrator is
granted it by the same expression naming both roles.

**The first administrator is created by a console command**,
`app:create-administrator`, which creates an account or promotes an existing one
and sets its password.

## Alternatives considered

**`role_hierarchy: ROLE_ADMIN: [ROLE_EDITOR]`.** The conventional answer, one
line of YAML, and genuinely less code than what was built. Rejected on two
grounds. It is invisible at the point of use — reading `ArticleVoter` would not
tell you that an administrator reaches the editor branch — and it is untestable
except by booting the container, which turns a unit test into an integration
test for no gain. The deeper objection is that a hierarchy grants *future* editor
permissions to administrators automatically. That is usually what you want and it
is never a decision anybody made; the day it is wrong, nothing will have flagged
it.

The cost is real and worth stating: every future permission has to name both
roles, and somebody will forget one. What catches that is the permission matrix
in the voter tests, which asserts every role against every action rather than
sampling.

**One voter for everything, switching on the subject type.** Rejected because
`supports()` becomes the place bugs hide. A subject the voter fails to recognise
is silently unhandled; unhandled means abstain; abstain means the decision falls
through to whatever else is configured. Three voters with narrow `supports()`
methods fail loudly instead.

**Checking roles inline in controllers** — `if ($this->isGranted('ROLE_EDITOR'))`.
Rejected: it cannot see ownership, so the author rule would have to be written
again beside every check, and written again is written differently.

**Seeding an administrator in a migration.** Rejected outright: a migration that
inserts a credential ships a known account to every environment that ever runs
it, including production.

**Seeding one in the fixtures.** Rejected: fixtures are development-only and they
purge the database, so they cannot be the way a real installation gains access.

## Consequences

- Every grant is visible in the code that performs it, and provable in a unit
  test that needs no container and no database.
- **Every new permission must name both `ROLE_EDITOR` and `ROLE_ADMIN` where both
  should hold it.** The voter tests are what stop that being forgotten; they
  assert the whole matrix, including the refusals.
- Voters receive entities and return booleans. Nothing under `src/Entity/` or
  `src/Service/` learns that authorisation exists, so principle I holds.
- `app:create-administrator` can create or promote an account on any
  installation, which is what makes a first sign-in possible before feature 004
  builds an interface. It is also a command that hands out administrative access,
  so it exists only where somebody already has shell access to the server.
- Nothing here rate-limits the sign-in form. That is out of scope for this
  feature and recorded in `docs/status.md` as absent, because "the door is
  locked" invites the assumption that it is also guarded.
