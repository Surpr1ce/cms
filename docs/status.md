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
| PHPStan (level max) + Symfony/Doctrine extensions | Configured |
| PHP-CS-Fixer (Symfony ruleset, PHP 8.4 migration) | Configured |
| Rector (PHP 8.4 + quality sets) | Configured |
| PHPUnit, Foundry, DAMA isolation | Installed, not yet used |
| EasyAdmin 5, API Platform 4, Tailwind bundle | Installed, not yet configured |
| Composer quality-gate scripts (`composer qa`) | Defined |
| GitHub Actions CI | Written, not yet run |
| Spec Kit + project subagents | Installed |
| Conventions (`CLAUDE.md`) and ADRs 1–4 | Written |
| PostgreSQL 16 on the development machine | Installed and running, connection verified |
| Feature 001 (`specs/001-core-content-model/`) | Specified, planned, tasks generated (79 tasks across 9 phases); no code written yet |

## Not done

| Area | State |
| --- | --- |
| Entities, repositories, migrations | Specified, not started |
| Fixtures and factories | Not started |
| Security configuration, voters, login | Not started |
| Admin screens (EasyAdmin and hand-written) | Not started |
| Public frontend templates | Not started |
| Media upload handling | Not started |
| Read-only API resources | Not started |
| Test suite | Not started |
| Security and quality audits | Not started |

## Known constraints

- **Docker is unavailable on the development machine.** WSL2 is not installed and
  Docker Desktop cannot start. PostgreSQL runs natively instead; see
  [ADR 3](adr/0003-postgresql-natively-instead-of-docker.md). `compose.yaml` is
  retained but untested on this host.
- **The API is read-only by design**, so it is not a complete headless interface.
  See [ADR 2](adr/0002-twig-monolith-with-read-only-api.md).
- **CI has not executed yet**, so the workflow file is unverified. It is marked
  done above only as written, not as proven.
