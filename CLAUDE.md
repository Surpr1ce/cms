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
| Admin | EasyAdmin 5 (generic CRUD) + hand-written Twig controllers (content, media) |
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
  Service/         Application services (slugging, publishing, uploads)
  Controller/      HTTP boundary — thin, delegates to services
    Admin/         Hand-written admin screens
  Security/        Voters, authenticators
  Twig/            Extensions and components
```

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
   Failing tests are reported, never silently skipped.
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
  `templates/components/`.
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

PostgreSQL runs natively on this machine (Docker Desktop requires WSL2, which is
not available here). `compose.yaml` is kept for environments that do have Docker,
and CI uses a Postgres service container.
