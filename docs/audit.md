# Audit

Date: 2026-08-17. Against `master` at seventeen merged features, 864 tests.

What this is: every convention this project set for itself, checked against what
is actually in the repository, one at a time. It was asked for after three
features in a row had consisted entirely of faults found by opening the running
site rather than by running the suite.

What it is not: the `symfony-reviewer` and `security-auditor` passes the
constitution requires at phase 4 of every feature. **Those have still never
run** — see the last section.

## Conventions in `CLAUDE.md`

| Convention | Result |
| --- | --- |
| `declare(strict_types=1)` in every PHP file | **Pass.** 198 files; the three without it are Symfony's own generated `config/bundles.php`, `preload.php` and `reference.php` |
| Attributes, never annotations | **Pass.** No `@ORM\`, `@Route(` or `@Assert\` anywhere |
| Constructor injection; no `ContainerInterface`, no service locators | **Pass** in `src/` |
| Repositories return typed collections, never a `QueryBuilder` | **Pass.** No public repository method returns one |
| Entities have no setter where an invariant applies | **Pass.** The only `set*` on a guarded field is `User::setRoles()`, which is an administrator's decision and is what the accounts screen exists to make |
| Templates in the agreed directories | **Fixed.** `templates/email/` and `templates/bundles/` existed and were not listed; `CLAUDE.md` now names them |
| Migrations generated, never hand-edited after commit | **Pass**, with one exception recorded in feature 011: the full-text index migration is hand-written, because `doctrine:migrations:diff` describes tables and columns and an expression index is neither |
| Every entity has a Foundry factory | **Was failing.** `AuditEntry` had none. Written, and used by the audit log tests |
| One concern per commit, conventional messages | **Pass** by inspection of the log |

## The constitution

| Principle | Result |
| --- | --- |
| I. Domain independent of delivery | **Two deviations, now recorded.** The media services take `HttpFoundation\File`, and `AuditLog` reads the actor from the session. Both argued and accepted in [ADR 13](adr/0013-two-places-where-the-domain-knows-about-delivery.md) — the point being that the principle now has two named exceptions rather than an unknown number |
| II. Specification before implementation | **Pass.** Seventeen features, seventeen `specs/*/spec.md` and `tasks.md`, seventeen merges |
| III. The quality gate is not negotiable | **Pass.** `composer qa` green on every merge; CI green since feature 011, and red for four features before anybody looked — recorded in `status.md` |
| IV. Tests prove failure paths | **Pass.** Every static `/admin` address appears in a test asserting a refusal; the invisibility of unpublished content is asserted through five delivery mechanisms |
| V. Decisions are recorded | **Pass.** Thirteen ADRs |
| VI. Status is reported honestly | **Pass now.** It was not: `status.md` claimed CI had never run for eleven features while it was failing on every push |

## Hygiene

| Check | Result |
| --- | --- |
| Dependency advisories | **None.** `composer audit` clean |
| Direct dependencies out of date | **None** |
| Deprecations at runtime | **One was ours** — `zenstruck_foundry.enable_auto_refresh_with_lazy_objects` unset, deprecated since Foundry 2.7. Set explicitly. The rest come from Symfony's own web profiler templates |
| `TODO`, `FIXME`, `dd()`, `dump()`, `var_dump()` left in the code | **None** |
| Secrets in committed files | **None.** `APP_SECRET` is empty in `.env` and set in the gitignored `.env.local` |
| Build artefacts tracked by git | **None.** `var/`, `public/assets/` and `.env.local` are all ignored |
| Dead code | **One class.** `PasswordResetRequestFactory` was unused; it is used now, and it replaced a raw `UPDATE` in a test that was moving a timestamp because the entity rightly has no setter for it |
| Confusable names | **One pair.** `Admin\AccountController` (your own account) and `Admin\AccountsController` (everybody's) differed by one letter in one namespace. Renamed to `MyAccountController` and `AccountController` |

## Coverage

From the CI artefact: **86.76% of lines**, 82.27% of methods, 58.67% of classes.

The class figure is low because a class counts as covered only when every one of
its methods is, so a single unexercised accessor drops the whole file. The line
figure is the one worth reading.

## What the audit could not check

**The `symfony-reviewer` and `security-auditor` passes have never run**, for any
of the seventeen features. The constitution requires both at phase 4. No session
that built this project was able to spawn them, and the mechanical checks
recorded in each feature's `tasks.md` are not the same thing as a reviewer
reading the code.

This is the one outstanding debt in the project and it is deliberate that it is
stated last and plainly, rather than folded into a table where it would read as
one row among thirty.

**Nothing here checks what the site looks like.** Three consecutive features and
one follow-up commit were made entirely of faults invisible to 864 passing tests:
images that were one pixel, form fields with no border, two administration
interfaces, a footer fighting its own layout, and a stylesheet eight features out
of date being served to the browser while the correct one sat on disk. An audit
of conventions would have found none of them.
