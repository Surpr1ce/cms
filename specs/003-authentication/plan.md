# Implementation Plan: Authentication and Authorisation

**Branch**: `003-authentication` | **Date**: 2026-08-17 | **Spec**: [spec.md](spec.md)

## Summary

A sign-in form, a firewall over `/admin`, and voters that answer permission
questions about specific content.

The technical approach in one sentence: **role checks decide where somebody may
go, voters decide what they may do to a particular thing, and the two are kept
apart because conflating them is what makes an author able to edit somebody
else's draft.**

Concretely:

- `access_control` closes `/admin` to anybody not signed in. That is a coarse
  gate and it is all it should be.
- Everything finer — may this person edit *this* article — is a voter with an
  explicit subject. There is no `isGranted('ROLE_EDITOR')` guarding a domain
  action anywhere in this feature, because a role check cannot see ownership.
- An administrator's permissions are granted by the voters explicitly, not by
  `role_hierarchy`. Configuration-based inheritance is invisible at the point of
  use and untestable except through the container.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: `symfony/security-bundle` — installed since the
skeleton, configured for the first time here. **No new Composer dependency.**

**Storage**: no schema change. `User` already carries everything needed. **No
migration.**

**Testing**: PHPUnit 13. This is the feature where `docs/testing.md`'s
wrong-role rule finally binds: every protected address gets an anonymous case
*and* an insufficient-role case.

**Constraints**: PHPStan level max; `composer qa` untouched.

**Scale/Scope**: 1 controller, 3 voters, 1 console command, 2 templates,
security configuration. Roughly 60 tests, most of them a permission matrix.

## Constitution Check

| Principle | Verdict |
| --- | --- |
| **I. Domain Independent of Delivery** | **Pass.** Voters live in `src/Security/`, which `CLAUDE.md` already names as the place for them. They receive entities and return booleans; nothing under `src/Entity/` or `src/Service/` learns that authorisation exists. |
| **II. Specification Before Implementation** | **Pass** — and this time `tasks.md` is written before the code, correcting the departure reported in feature 002. |
| **III. Quality Gate Is Not Negotiable** | **Pass, planned.** |
| **IV. Tests Prove Failure Paths** | **Pass, and this is the feature the rule was written for.** Every protected address gets both cases. Every voter gets its refusals tested, not only its grants — a voter that returns true for everything passes every happy-path test ever written. |
| **V. Decisions Are Recorded** | **Pass, with work.** Two decisions qualify: voters instead of `role_hierarchy`, and the console command that bootstraps the first administrator. Planned as ADR 9. |
| **VI. Status Is Reported Honestly** | **Pass** — including recording that rate limiting on the sign-in form is *not* implemented, which a reader might otherwise assume from "the door is locked". |

**Post-Phase 1 re-check**: passed. One thing for a reviewer: `/admin` has no
routes yet. The firewall and `access_control` are configured over an address
space that is empty until feature 004. That is deliberate — the lock arrives
before the door it guards — and the tests use a single throwaway route to prove
the gate works rather than waiting for real ones.

## Project Structure

```text
src/
├── Controller/
│   └── SecurityController.php        # /login, /logout
├── Security/
│   ├── ArticleVoter.php              # view, edit, delete, publish — with ownership
│   ├── PageVoter.php                 # role only; a page has no author
│   └── AdministrationVoter.php       # manage taxonomy, files, accounts
└── Command/
    └── CreateAdministratorCommand.php   # bootstrap, because SC-005

templates/public/security/login.html.twig
config/packages/security.yaml          # rewritten

tests/
├── Unit/Security/                     # the permission matrix, no HTTP
│   ├── ArticleVoterTest.php
│   ├── PageVoterTest.php
│   └── AdministrationVoterTest.php
├── Integration/Command/CreateAdministratorCommandTest.php
└── Functional/
    ├── LoginTest.php
    └── AdministrationIsClosedTest.php
```

**Structure Decision**: unchanged three layers. `src/Security/` and `src/Command/`
are both already named in `CLAUDE.md`… `Command/` is not, and is added to the
architecture tree in the same change rather than left as drift — the same
correction made for `Exception/` and `Factory/` in feature 001.

## Complexity Tracking

| Choice | Why needed | Simpler alternative rejected because |
|--------|------------|--------------------------------------|
| Three voters rather than one | The subjects are different kinds of thing: an article has an owner, a page does not, and "manage accounts" has no subject at all. One voter would need a `switch` on the subject type, which is three voters wearing a coat | A single voter was rejected because `supports()` would become the place bugs hide: a subject it fails to recognise is silently unhandled, and unhandled means abstain, and abstain means the decision falls to whatever else is configured |
| Explicit grants instead of `role_hierarchy` | Every grant is then visible in code and provable in a unit test. `role_hierarchy` is a YAML fact that no test touches and that reads as configuration rather than as a rule | Hierarchy is the conventional answer and is genuinely less code. Rejected because "an administrator may do what an editor may" is a domain rule this project keeps in testable code, and because a hierarchy silently grants future editor permissions to administrators, which is usually right and is never *decided* |
| A console command for the first administrator | Access has to exist before the interface that grants it. Fixtures cannot be the answer: they are development-only and they purge the database | Seeding an administrator in a migration was rejected — a migration that inserts a credential ships a known account to every environment that runs it |
