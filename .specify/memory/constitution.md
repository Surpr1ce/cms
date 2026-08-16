<!--
Sync Impact Report
==================
Version change: none → 1.0.0 (initial ratification)

Added principles:
  I.   Domain Independent of Delivery (NON-NEGOTIABLE)
  II.  Specification Before Implementation
  III. The Quality Gate Is Not Negotiable (NON-NEGOTIABLE)
  IV.  Tests Prove Failure Paths
  V.   Decisions Are Recorded
  VI.  Status Is Reported Honestly

Added sections:
  Technology Constraints
  Development Workflow

Removed sections: none

Deferred items: none
-->

# CMS Constitution

## Core Principles

### I. Domain Independent of Delivery (NON-NEGOTIABLE)

No class under `src/Entity/` or `src/Service/` MUST reference `Request`,
`Response`, `Session`, Twig, or any other delivery concern. Controllers and API
Platform resources are the only translation points between HTTP and the domain.

Rationale: the project serves the same content through Twig templates and a JSON
API. That is only true if one domain layer feeds both. The moment a publishing
rule lives in a controller, the API returns different content from the website,
and the divergence is silent.

### II. Specification Before Implementation

Any change beyond a bug fix or a mechanical refactor MUST have a specification in
`specs/` before code is written. The specification states requirements and
acceptance criteria, not implementation. Implementation that diverges from its
specification MUST update the specification in the same pull request.

Rationale: the specification is what makes the work reviewable. Code alone shows
what was built and hides what was intended, so divergence becomes invisible.

### III. The Quality Gate Is Not Negotiable (NON-NEGOTIABLE)

`composer qa` MUST pass before any change is considered complete: PHP-CS-Fixer,
Rector in dry-run mode, PHPStan at level max, and the full PHPUnit suite. CI runs
the same gate on a clean checkout.

Lowering the PHPStan level, adding a baseline, suppressing a finding with an
ignore comment, or deleting a failing test in order to pass the gate is
prohibited. A finding is fixed at its cause. If a check is genuinely wrong, it is
changed deliberately and the reason is stated in the commit message.

Rationale: a gate that can be lowered when it is inconvenient measures nothing.
The value of the gate is entirely in its being unconditional.

### IV. Tests Prove Failure Paths

Every route MUST have a functional test for the anonymous-user case and the
insufficient-privilege case, not only the authorised happy path. Every domain
invariant MUST have a test that proves violating it fails.

Coverage is reported but MUST NOT be treated as a target. A percentage raised by
executing code without asserting on its behaviour is a worse outcome than a lower
honest number, because it hides the gap it appears to close.

Rationale: in a CMS, the interesting defects are authorisation failures and
invalid state transitions. Happy-path tests cannot detect either.

### V. Decisions Are Recorded

A decision MUST be recorded as an ADR in `docs/adr/` when reversing it would
require changes in more than one layer, or when a reasonable engineer would ask
why it was done that way. Records state the alternatives that were rejected and
why.

Accepted records are immutable. A decision that no longer holds is superseded by
a new record, never edited in place.

Rationale: the work is produced across many sessions with AI assistance. Without
recorded reasoning, later sessions re-argue settled questions or quietly reverse
them, and a reviewer cannot distinguish a considered choice from an accident.

### VI. Status Is Reported Honestly

`docs/status.md` MUST reflect what exists in the codebase, not what is planned.
Design documents describing an intended state MUST say so at the top. Work that
is incomplete, blocked, or unverified MUST be reported as such — including in
commit messages, pull requests, and agent output.

A test suite that fails is reported as failing. A workflow file that has never
executed is "written", not "working".

Rationale: an overstated status is more damaging than a missing one, because it
removes the reader's reason to check.

## Technology Constraints

The stack is fixed for the duration of this project: PHP 8.4, Symfony 8.1, Twig,
Doctrine ORM 3, PostgreSQL 16, EasyAdmin for generic CRUD, API Platform for the
read-only JSON API, AssetMapper with Tailwind and no Node build step.

Additional constraints:

- Every PHP file MUST declare `strict_types=1`.
- Configuration MUST use PHP attributes, never annotations.
- Dependencies MUST be injected through the constructor. Service locators and
  direct container access are prohibited outside the framework's own bootstrap.
- All code, comments, documentation, and commit messages MUST be in English.
- Migrations MUST be generated with `doctrine:migrations:diff` and MUST NOT be
  edited after being committed.
- Uploaded files MUST be stored under generated filenames, validated by content
  rather than by extension, and served through a controller that applies
  authorisation.

Docker is unavailable on the development machine; PostgreSQL runs natively. See
`docs/adr/0003-postgresql-natively-instead-of-docker.md`.

## Development Workflow

Work proceeds in five phases, each leaving an artifact in the repository:

1. **Discuss** — establish what is being built and why. Produces an ADR or a
   decision recorded in the specification.
2. **Plan** — `/speckit-specify`, then `/speckit-plan`, then `/speckit-tasks`.
   Produces `specs/NNN-feature/spec.md`, `plan.md`, and `tasks.md`.
3. **Execute** — implement against the plan, one concern per commit, using
   Conventional Commits.
4. **Verify** — `composer qa` passes; the `symfony-reviewer` agent reviews the
   change; `security-auditor` runs when security surfaces are touched.
5. **Ship** — merge, push, and update documentation in the same change.

A phase that left no artifact did not happen.

## Governance

This constitution supersedes other practices in this repository. Where
`CLAUDE.md` and this document appear to conflict, this document governs and
`CLAUDE.md` MUST be corrected.

**Amendments** require a pull request that states the principle being changed,
the reason, and the migration path for existing code that no longer complies. An
amendment that weakens a NON-NEGOTIABLE principle MUST additionally explain why
the original rationale no longer holds.

**Versioning** follows semantic versioning: MAJOR for removing or redefining a
principle in a backward-incompatible way, MINOR for adding a principle or
materially expanding guidance, PATCH for clarifications and wording.

**Compliance** is verified at review time. Every pull request MUST state which
principles its changes touch. Violations are fixed before merge, not recorded as
follow-up work. Runtime development guidance lives in `CLAUDE.md`.

**Version**: 1.0.0 | **Ratified**: 2026-08-16 | **Last Amended**: 2026-08-16
