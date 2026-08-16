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

## Not done

| Area | State |
| --- | --- |
| Phase 9 of feature 001 | Documentation and review tasks in progress |
| Functional tests | **None, because the project has no routes yet.** `docs/testing.md` requires an anonymous-user case and a wrong-role case for every route; that requirement starts applying to the first feature that adds one. It is not waived |
| Security configuration, voters, login | Not started. `config/packages/security.yaml` is still the skeleton default with an in-memory provider |
| Admin screens (EasyAdmin and hand-written) | Not started. EasyAdmin is installed but unconfigured |
| Public frontend templates | Not started |
| Media upload handling | Not started. Feature 001 catalogues files; it does not receive, validate, store or serve them |
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

## Known constraints

- **Docker is unavailable on the development machine.** WSL2 is not installed and
  Docker Desktop cannot start. PostgreSQL runs natively instead; see
  [ADR 3](adr/0003-postgresql-natively-instead-of-docker.md). `compose.yaml` is
  retained but untested on this host.
- **The test environment needs `.env.test.local`.** Symfony deliberately does not
  load `.env.local` when `APP_ENV=test`, so local database credentials have to be
  repeated there. The file is gitignored; CI sets `DATABASE_URL` itself.
- **The API is read-only by design**, so it is not a complete headless interface.
  See [ADR 2](adr/0002-twig-monolith-with-read-only-api.md).
