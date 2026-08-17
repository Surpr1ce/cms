# Implementation status

Last updated: 2026-08-16

This file records what actually exists in the codebase, as distinct from what the
design documents describe. Documents that describe an intended design are marked
as such at the top of the file.

## Done

| Area | State |
| --- | --- |
| Symfony 8.1 skeleton | Installed, PHP 8.4 |
| Repository and remote | `Surpr1ce/cms`, private |
| PostgreSQL 16 on the development machine | Installed and running, dev and test databases migrated |
| PHPStan (level max) + Symfony/Doctrine extensions | Configured, passing with no baseline and no ignores |
| PHP-CS-Fixer (Symfony ruleset, PHP 8.4 migration) | Configured, passing |
| Rector (PHP 8.4 + quality sets) | Configured, passing |
| PHPUnit with Foundry and DAMA isolation | In use; `unit`, `integration` and `functional` suites defined |
| Composer quality-gate scripts (`composer qa`) | Defined and green |
| Spec Kit + project subagents | Installed |
| Conventions (`CLAUDE.md`), constitution, ADRs 1–6 | Written |

### Feature 001 — core content model

Branch `001-core-content-model`. Specification, plan, research, data model,
contract and task list in `specs/001-core-content-model/`.

| Area | State |
| --- | --- |
| Phases 1–8 of 9 | Built, `composer qa` green after each |
| Entities | `User`, `Article`, `Page`, `Category`, `Tag`, `Media`, the abstract `PublishableContent`, the `ContentStatus` enum, the `Slug` rule holder |
| Repositories | Six, plus the `SluggedRepository` interface. Published scope is one private method per repository; no method returns a `QueryBuilder` |
| Services | `SlugGenerator`, `UniqueSlugGenerator`, `StoredFilenameGenerator`, `CategoryDeleter`, `PageDeleter`, `MediaDeleter`, `UserDeleter` |
| Domain exceptions | Nine, each carrying its context as typed accessors |
| Migrations | Five, all generated with `doctrine:migrations:diff`, none hand-edited after commit |
| Foundry factories | Six, one per entity, used by both the tests and the fixtures |
| Development fixtures | `AppStory` builds 4 accounts, 3 sections, 5 labels, 12 articles across all three states, 6 pages and 6 files |
| Test suite | **279 tests, 586 assertions, passing** |

### Feature 002 — public website

Branch `002-public-website`. Merged into feature 001's model; the site renders.

| Area | State |
| --- | --- |
| Phases 1–8 | Built, `composer qa` green |
| Routes | `/`, `/articles/{slug}`, `/sections/{slug}`, `/topics/{slug}`, `/{slug}` — see [ADR 8](adr/0008-public-address-scheme.md) |
| Controllers | Five, thin; none queries directly and none checks a status — every route resolves through a repository method that cannot return unpublished content |
| Templates | Site layout, home, article, section, label, page, four components, and 404 and error pages inside the site's own layout |
| Styling | Tailwind v4.3.3, pinned, built from a standalone binary. No Node, no `package.json`, no `node_modules` |
| Pagination | 20 per page, next/previous only, one extra fetched row instead of a `COUNT` |
| Menu | One query per request, built by a Twig extension so no controller can forget it |
| Functional test suite | **73 tests** — the suite that was empty until this feature |
| Whole project | **379 tests, 780 assertions, passing** |

### Feature 003 — authentication and authorisation

Branch `003-authentication`. The door is fitted; the rooms behind it are feature 004.

| Area | State |
| --- | --- |
| Sign-in and sign-out | `/login`, `/logout`, form login against an entity provider, CSRF-protected |
| The gate | `^/admin` requires a signed-in account holding a content role. Anonymous → redirect; recognised but unpermitted → 403 |
| Voters | `ArticleVoter` (ownership plus role), `PageVoter` (role only — a page has no author), `AdministrationVoter` (taxonomy, files, accounts) |
| Role model | **No `role_hierarchy`.** An administrator is granted an editor's permissions explicitly in each voter, so every grant is visible and unit-testable. See [ADR 9](adr/0009-voters-instead-of-role-hierarchy.md) |
| Bootstrap | `php bin/console app:create-administrator` creates or promotes an account, so access exists before any interface does |
| Fixture accounts | All four can sign in, with the password written openly in `UserFactory::DEVELOPMENT_PASSWORD` |
| Whole project | **492 tests, 973 assertions, passing** |

**Two defects the tests found before the code shipped**: the article voter
granted permission on ownership alone, so an account whose author role had been
revoked would have kept every permission over everything it wrote; and two tests
of role revocation passed while proving nothing, because they wrote through a
discarded entity manager. Both are recorded in the feature's `tasks.md`.

## Not done

| Area | State |
| --- | --- |
| **Rate limiting on the sign-in form** | **Not implemented.** Nothing counts how many times somebody tries the handle. Listed prominently because "the administration area is closed" invites the assumption that it is also guarded, and it is not |
| Registration, password reset, password change, email | Not started |
| "Remember me", two-factor, session expiry policy | Not started |
| Audit log of who did what | Not started |
| `symfony-reviewer` pass on features 001, 002 and 003 | **Open.** The constitution requires it at phase 4 of the workflow; the sessions that built both features could not spawn subagents. Mechanical checks were verified directly and the evidence is in each feature's `tasks.md` |
| Wrong-role functional tests | Not applicable yet. `docs/testing.md` requires an anonymous case *and* a wrong-role case for every route; every route so far is public, so there is no role to be wrong. It starts applying with the first protected route |
| Admin screens (EasyAdmin and hand-written) | Not started. EasyAdmin is installed but unconfigured, and `/admin` is a placeholder page saying so |
| Media upload handling | Not started. Feature 001 catalogues files and feature 002 renders a lead image from the recorded filename, but nothing puts bytes on disk — so an article with an image renders without it, by design |
| Search, feeds, sitemap, social preview metadata | Not started |
| Caching of any kind | Not started, and deliberately so — the menu costs one query per request |
| Read-only API resources | Not started. API Platform is installed but exposes nothing |
| Security and quality audits | Not started |
| GitHub Actions CI | Written, **never executed**. The workflow is unverified |

## Known gaps in what *is* built

Recorded because behaviour that looks complete and is not is worse than a missing
feature.

- **Slug regeneration is not enforced.** `PublishableContent` guarantees an
  address stops changing after publication, because that needs no other row. It
  cannot guarantee an address is regenerated when a draft's title changes,
  because uniqueness needs the database — that is `UniqueSlugGenerator`'s job,
  and a caller that sets a title without going through it leaves the slug stale.
  Acceptable while the only callers are tests and fixtures; it closes when the
  administration layer gives editing a single entry point. See
  [ADR 6](adr/0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md).
- **A published address cannot be changed at all.** Renaming published content
  with a redirect is a legitimate future need and will need its own decision — an
  alias table — rather than a relaxation of the freeze.
- **`User::$password` starts empty.** Symfony's hasher needs the user object to
  choose a hasher, so the account exists before its hash does. An empty hash
  matches nothing, so the intermediate state cannot authenticate.
- **Content markup is rendered unsanitised.** Article and page bodies are stored
  as supplied and rendered as markup, which is what FR-024 of feature 002 asks
  for. Nothing sanitises them. This is safe only because there is no editor yet
  and the only author is a developer loading fixtures. **Whichever feature first
  lets somebody paste markup into the CMS inherits this obligation**, and it is
  the single most important thing to get right in the administration feature.
- **A page can never be called `articles`, `sections`, `topics`, `api` or
  `admin`**, and any future root-level prefix adds to that list. See
  [ADR 8](adr/0008-public-address-scheme.md).

## Known constraints

- ~~**Docker is unavailable on the development machine.**~~ **No longer true as of
  2026-08-17.** Docker 29.7.2 with WSL2 is installed and working, and
  `compose.yaml` has been verified — `docker compose up -d database` reaches a
  healthy PostgreSQL 16.15 container. Native PostgreSQL remains the default
  because it holds the migrated databases; the compose stack is a supported
  alternative. See [ADR 7](adr/0007-docker-is-available-after-all.md), which
  supersedes [ADR 3](adr/0003-postgresql-natively-instead-of-docker.md).
- **The test environment needs `.env.test.local`.** Symfony deliberately does not
  load `.env.local` when `APP_ENV=test`, so local database credentials have to be
  repeated there. The file is gitignored; CI sets `DATABASE_URL` itself.
- **The API is read-only by design**, so it is not a complete headless interface.
  See [ADR 2](adr/0002-twig-monolith-with-read-only-api.md).
