# CMS — Project Conventions

A content management system built with Symfony 8.1 and Twig, developed with an
AI-assisted, spec-driven workflow. This file is the contract every contributor —
human or agent — works under.

## Stack

| Layer | Choice |
| --- | --- |
| Runtime | PHP 8.4 |
| Framework | Symfony 8.1 |
| Templating | Twig |
| Persistence | Doctrine ORM 3 + PostgreSQL 16 |
| Admin | Hand-written Twig controllers throughout |
| Read API | API Platform 4, read-only |
| Assets | AssetMapper + Tailwind (no Node build step) |
| Tests | PHPUnit 13, Foundry, DAMA transaction isolation |
| Analysis | PHPStan (level max), PHP-CS-Fixer, Rector |

## Architecture

Three layers, dependencies point inwards only:

```
src/
  Entity/          Doctrine entities — domain state and invariants
  Repository/      Query objects; no business rules
  Search/          Site search — the query, its read model, and the one query
                   object that answers it
  Service/         Application services (slugging, publishing, uploads)
  Exception/       Domain exceptions — one class per refused rule
  Factory/         Foundry factories — one per entity, used by fixtures and tests
  Command/         Console commands — operator tasks with no HTTP surface
  Form/            Form types, and the command objects they fill
    Command/       Plain data carrying what a form collected — scalars, enums and
                   the entities somebody picked from a list; never the entity being
                   edited, and never a repository, a service or a manager
  Controller/      HTTP boundary — thin, delegates to services
    Admin/         Hand-written admin screens
  Security/        Voters, authenticators
  Twig/            Extensions and components
```

`Exception/` holds one class per rule that can be refused, all extending
`App\Exception\DomainException`. Distinct classes let a test assert on the rule
that was broken rather than on a message string, and let controllers map each to
a different response without parsing text.

`Search/` is its own namespace rather than a repository method because a result
list ranks articles and pages against each other, which belongs to neither
entity's repository — and because it is the one read path that cannot reuse a
published-only repository method, so the rule that keeps unpublished work
invisible is written there in full rather than inherited.

`Factory/` is test-support code that lives in `src/` rather than `tests/` because
`src/DataFixtures/` and `src/Story/` load development data through the same
factories. Keeping one definition of what a valid entity looks like is worth the
cost of analysing test-support code at PHPStan level max.

**The domain must not know about HTTP or Twig.** Controllers translate requests
into service calls and hand results to templates. This is what makes the same
data serveable through both Twig and the JSON API — keep it that way.

## Working rules

Development runs as a repeating five-phase loop. Do not skip phases; each one
leaves an artifact in the repository.

1. **Discuss** — capture the decision and its alternatives before writing code.
   Non-obvious choices become an ADR in `docs/adr/`.
2. **Plan** — use `/speckit-specify` and `/speckit-plan`. Specifications live in
   `specs/`; they are deliverables, not scratch notes.
3. **Execute** — implement against the plan. One concern per commit.
4. **Verify** — `composer qa` must pass before anything is considered done.
   Failing tests are reported, never silently skipped. Before a merge, run the
   `architecture-guardian` and `security-auditor` agents: the gate proves the rules
   somebody thought to write down, and those two look for the ones nobody did.
5. **Ship** — commit with a conventional message, push, update documentation.

## Conventions

- **Language**: all code, comments, documentation, and commit messages in English.
- **Strict types**: every PHP file starts with `declare(strict_types=1);`.
- **No annotations**: PHP attributes only — for Doctrine mapping, routing,
  validation, and security.
- **Constructor injection**: no `ContainerInterface`, no service locators.
- **Entities**: private properties, typed getters, no public setters where an
  invariant applies — express state changes as intention-revealing methods
  (`publish()`, `archive()`), not `setStatus()`.
- **Repositories**: return typed collections; never leak `QueryBuilder`.
- **Templates**: `templates/admin/` and `templates/public/`; shared partials in
  `templates/components/`; messages in `templates/email/`; the form theme in
  `templates/form/theme.html.twig`, registered globally so a new form is styled
  without anybody remembering. `templates/bundles/` overrides a bundle's own —
  today only the error pages.
- **Migrations**: generated with `doctrine:migrations:diff`, never hand-edited
  after being committed.

## Quality gate

```bash
composer qa          # style + refactoring + static analysis + tests
composer cs          # apply style fixes
composer test        # PHPUnit only
composer phpstan     # static analysis only
```

PHPStan runs at **level max**. Do not lower the level or add a baseline entry to
silence a finding — fix the code or, if the finding is genuinely wrong, add a
narrowly scoped ignore with a comment explaining why.

**The architecture is part of the gate too.** PHPStan at level max is perfectly
happy with an entity that renders a template, so `tests/Unit/Architecture/`
asserts what this section describes: the import matrix per layer, no
`QueryBuilder` leaving a repository, no query built in a controller, no action
over 25 lines of code, no class over seven constructor dependencies, no reach for
the container, no mutable static state, `final` everywhere it belongs, and the
three exceptions [ADR 13](docs/adr/0013-two-places-where-the-domain-knows-about-delivery.md)
records still being the only three, each pinned to the one file allowed to hold
it. It boots nothing and runs in a tenth of a
second. A rule that matters and cannot be asserted belongs in
`.claude/agents/architecture-guardian.md` instead — never in prose alone.

## Testing

- **Unit** (`tests/Unit/`) — domain logic, no container, no database.
- **Integration** (`tests/Integration/`) — repositories and services against a
  real database.
- **Functional** (`tests/Functional/`) — HTTP-level tests through `KernelBrowser`,
  covering both admin and public routes.

Every entity gets a Foundry factory. Tests never depend on execution order; DAMA
wraps each test in a transaction and rolls it back.

## Commits

Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`.
Subject in imperative mood, no trailing period. Reference the spec when one
exists (`feat(article): add publishing workflow (specs/003-article-publishing)`).

## Local setup

```bash
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
symfony serve            # or: php -S localhost:8000 -t public
```

PostgreSQL runs natively on this machine by default. Docker is also available and
`compose.yaml` is verified — `docker compose up -d database` works — but the
native instance holds the migrated databases, so it stays the default. Full
instructions for both paths are in [`docs/setup.md`](docs/setup.md); the reasoning
is in [ADR 7](docs/adr/0007-docker-is-available-after-all.md), which supersedes
ADR 3. CI uses a Postgres service container.

`.env.test.local` is required and gitignored: Symfony does not load `.env.local`
when `APP_ENV=test`, so the test database credentials have to be repeated there.
