# Audit

Date: 2026-08-17. Against `master` at seventeen merged features, 864 tests.

What this is: every convention this project set for itself, checked against what
is actually in the repository, one at a time. It was asked for after three
features in a row had consisted entirely of faults found by opening the running
site rather than by running the suite.

What it is not: the `symfony-reviewer` and `security-auditor` passes the
constitution requires at phase 4 of every feature. **Those have since run** — see
[the section on what they found](#the-reviewer-and-security-passes-2026-08-17),
added the same day. They found twelve things this audit did not, including one
that was an account takeover.

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
| Secrets in committed files | **This row was wrong.** It said "None. `APP_SECRET` is empty in `.env` and set in the gitignored `.env.local`", and both halves are true — but `.env.dev` is also committed and carries a development `APP_SECRET`. The security pass found it. Its blast radius is `APP_ENV=dev` only, so it stays, deliberately: a secret everybody can read is one nobody can mistake for a real one. What was wrong was the claim, not the file |
| Build artefacts tracked by git | **None.** `var/`, `public/assets/` and `.env.local` are all ignored |
| Dead code | **One class.** `PasswordResetRequestFactory` was unused; it is used now, and it replaced a raw `UPDATE` in a test that was moving a timestamp because the entity rightly has no setter for it |
| Confusable names | **One pair.** `Admin\AccountController` (your own account) and `Admin\AccountsController` (everybody's) differed by one letter in one namespace. Renamed to `MyAccountController` and `AccountController` |

## Coverage

From the CI artefact: **86.76% of lines**, 82.27% of methods, 58.67% of classes.

The class figure is low because a class counts as covered only when every one of
its methods is, so a single unexercised accessor drops the whole file. The line
figure is the one worth reading.

## The reviewer and security passes (2026-08-17)

Both ran, once, across the whole repository rather than per feature. They are the
debt this document was written to name, and paying it was worth it: **twelve
findings, none of which the conventions audit above could have produced.**

Ranked by what they cost if left.

### Fixed

| # | Finding | Why it mattered |
| --- | --- | --- |
| 1 | **The password-reset link was built from the request's `Host:` header**, and nothing constrained it | Both passes put this first, independently. A stranger POSTs an administrator's address with `Host: attacker.example`; the administrator receives a genuine email *from this site* whose link leads to the attacker; one click hands over a live token, which `complete()` turns into a session. Hashing at rest, single use, one hour, throttling and identical responses are all bypassed rather than weakened, because the token is given away rather than guessed. Closed twice: `SYMFONY_TRUSTED_HOSTS` refuses the forged header, and the link is now generated from `DEFAULT_URI` whatever the request said |
| 2 | **`?page=9223372036854775807` was a 500** | The multiplication in `Paginator::offsetFor()` overflowed to a float, and returning a float from an `int` return type under strict types throws. Unauthenticated, on the front page, every listing, the search and the log. The unit test for an "absurdly large" page stopped at 999999 and never reached the multiplication |
| 3 | **An administrator could remove their own administrator permission** | FR-020 stops them *deleting* their own account so a site is never left unadministrable. Demotion reached the same place through the next door along, with no rule and no test. Recovery was shell access |
| 4 | **A cycle in the section tree was a 500** | The parent list offers a section's own children, so the wrong choice is one click away. The entity always refused it and carried a sentence saying why; the section screen never caught it, where the page screen next door always had |
| 5 | **Logout had no CSRF protection and accepted GET** | `csrf.yaml` listed `logout` among the stateless token ids, which read as though it were on. It was not: `<img src="…/logout">` on any page an editor visited signed them out |
| 6 | **An administrator could set their own password on the accounts screen**, which does not ask for the current one | The account page asks, and explains at length why. This was the way round it — turning a borrowed session into a permanent one by locking the owner out |
| 7 | **`^/admin` also closed public pages whose slug starts with those letters** | A published page called "Admin team" demanded a sign-in and was invisible to readers — the inverse of the property this project cares most about. Now `^/admin(/|$)` |
| 8 | **Deleting a section or a label recorded nothing** | `AuditAction` says the list covers everything that "removes something", and every other deletion recorded. Deleting a section silently uncategorises every article in it, which is exactly what somebody opens the log to explain |
| 9 | **`/api/tags/{slug}` answered for labels the collection deliberately hides** | `TagResource` documents that a label there is one carried by a published article; the item address contradicted its own type. The website's `/topics/{slug}` deliberately does *not* do this — FR-015 renders an empty listing rather than a 404, and nothing is disclosed by it, since a label used only by drafts and a label used by nothing produce the same page |
| 10 | **`/feed.xml` was an N+1** | Up to 41 queries where the front page showing the same twenty articles costs one |
| 11 | **Reset requests were never pruned, and `token_hash` had no index** | Every reset loaded and rewrote an account's entire history, and the form that grows that history needs no account at all. Every visit to a reset link was a sequential scan |
| 12 | **The minimum password length lived in four places** | Raise it in three of them and a route still accepts what the others refuse. Now `PasswordPolicy`, which also took the length and confirmation rules out of two controllers |

Two smaller ones went with them: `CreateAdministratorCommand` no longer takes the
password as an argument where shell history and `ps` can read it, and a service
being passed into a template that never used it is gone.

Sixteen tests were added, covering every refusal above rather than only the happy
path — including the two that can only be seen from outside: a forged `Host:` is
answered with 400, and the emailed link says `localhost` even when the request
was made to a *trusted* `127.0.0.1`, which is what proves the second defence
works on its own.

### Recorded, not fixed

Real, argued, and deliberately left — so that the next audit finds a list rather
than a surprise.

| Finding | Why it stays for now |
| --- | --- |
| The admin article list loads the whole table and runs a voter per row | Correct, and slow only past a few thousand articles. Fixing it properly means expressing `ARTICLE_VIEW` as a query so pagination and filtering agree, which is a feature rather than a fix |
| `EntityType` query builders live in `ArticleType`, `PageType`, `SectionType` | No repository returns a `QueryBuilder`, so the letter of the rule holds; the spirit does not, and "only files with alternative text" is now written twice. Worth a pass over the form layer, not worth a rushed one before a release |
| `MediaController::describe()` writes to an entity and flushes without a service, and states the alt-text rule a second time | Same shape of problem, same reasoning |
| A suffixed slug can exceed its 200-character column | Needs a ~200-character title *and* a collision. Fails as a driver exception rather than a form error |
| The page ancestor walk is a query per level | Bounded by menu depth |
| `_target_path` is an open-redirect surface *if* a template ever renders that field | None does, and a cross-site POST fails CSRF. Recorded so that adding one is recognised as dangerous |
| No `composer install --no-dev` check | `src/Factory/` and `src/Story/` extend classes from a `require-dev` package with no `exclude` in `services.yaml`. **Verify before deploying** |

### What both passes confirmed

Worth recording, because these are the parts most likely to be re-litigated:
uploads (type from magic bytes, allow-list, polyglot detection, random stored
names, storage outside the web root) are the strongest area in the codebase;
`SiteSearch` binds every parameter and both halves of its UNION filter on
published; authorisation is object-level everywhere with no role hierarchy and no
screen that hides a control the route still honours; the two `|raw` in the
templates are sound because everything reaching them passed the sanitiser and the
CSP carries no `unsafe-inline`; and neither pass found a way to reach unpublished
content through any delivery mechanism, the new "read next" suggestions included.

## What the audit could not check

**Nothing here checks what the site looks like.** Three consecutive features and
one follow-up commit were made entirely of faults invisible to 864 passing tests:
images that were one pixel, form fields with no border, two administration
interfaces, a footer fighting its own layout, and a stylesheet eight features out
of date being served to the browser while the correct one sat on disk. An audit
of conventions would have found none of them.
