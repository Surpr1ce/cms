# CMS

A content management system built with **Symfony 8.1**, **Twig**, and
**PostgreSQL**, developed using a spec-driven, AI-assisted workflow.

The application serves content through server-rendered Twig templates and, from
the same domain layer, through a read-only JSON API — so the CMS is usable
headlessly without a second frontend being built.

## Status

Under active development. See [`docs/status.md`](docs/status.md) for what is
implemented and what is not.

## Requirements

- PHP 8.4 with `ctype`, `iconv`, `pdo_pgsql`
- Composer 2.8+
- PostgreSQL 16

## Getting started

```bash
composer install
cp .env .env.local          # then set DATABASE_URL and APP_SECRET
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
symfony serve
```

The application is then available at <https://localhost:8000>, the admin at
`/admin`, and the API documentation at `/api`.

## Quality gate

```bash
composer qa
```

Runs coding-style checks, Rector in dry-run mode, PHPStan at level max, and the
full PHPUnit suite. The same gate runs in CI on every push and pull request.

Individual steps:

| Command | Purpose |
| --- | --- |
| `composer cs` | Apply coding-style fixes |
| `composer cs:check` | Report style violations |
| `composer rector` | Apply automated refactorings |
| `composer phpstan` | Static analysis at level max |
| `composer test` | PHPUnit suite |
| `composer test:coverage` | Suite plus HTML coverage in `var/coverage` |

## Documentation

| Document | Contents |
| --- | --- |
| [`CLAUDE.md`](CLAUDE.md) | Conventions every contributor works under |
| [`docs/architecture.md`](docs/architecture.md) | Layering, request flow, boundaries |
| [`docs/domain-model.md`](docs/domain-model.md) | Entities and their relationships |
| [`docs/ai-workflow.md`](docs/ai-workflow.md) | How the AI-assisted process works |
| [`docs/testing.md`](docs/testing.md) | Test strategy and layout |
| [`docs/adr/`](docs/adr/) | Architecture decision records |
| [`docs/audits/`](docs/audits/) | Security and quality audit reports |

## Licence

Proprietary — coursework project.
