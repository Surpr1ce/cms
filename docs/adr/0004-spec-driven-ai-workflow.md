# 4. Spec-driven AI workflow with Spec Kit

- **Status**: Accepted
- **Date**: 2026-08-16

## Context

The work is to be produced through an AI-assisted workflow, and the workflow
itself is part of what is assessed. Three frameworks were evaluated:

| Framework | Stars | Model | Artifacts in repository |
| --- | --- | --- | --- |
| [Spec Kit](https://github.com/github/spec-kit) | 129.5k | specify → plan → tasks → implement | Yes — specs, plans, task lists |
| [GSD Core](https://github.com/open-gsd/gsd-core) | 8.3k | five-phase loop, subagent context offloading | Yes — phase documents |
| [Ruflo](https://github.com/ruvnet/ruflo) | 68k | multi-agent swarm, vector memory, self-learning | Largely runtime state |

Ruflo is the most capable of the three in raw orchestration terms — 100+ agents,
35 plugins, persistent vector memory. It was rejected for this project on two
grounds: it produces coordination behaviour rather than reviewable documents, and
its surface area (including several hundred open issues) is more than can be
credibly understood and defended within a week.

GSD Core's contribution is its handling of context degradation across long
sessions. That is a real problem, but it is addressed here by a convention rather
than another dependency.

## Decision

Use **Spec Kit** as the primary workflow, installed with the Claude integration.
Features go through `/speckit-specify` → `/speckit-plan` → `/speckit-tasks` →
`/speckit-implement`, leaving specifications, plans, and task lists in the
repository.

Supplement it with three project-specific subagents in `.claude/agents/` —
`symfony-reviewer`, `security-auditor`, and `test-author` — which encode this
project's conventions rather than generic advice.

Adopt GSD Core's five-phase loop (Discuss → Plan → Execute → Verify → Ship) as a
documented rule in `CLAUDE.md`, without installing the tool.

## Consequences

- The development process leaves a reviewable paper trail: each feature can be
  traced from specification through plan to commit.
- One workflow system, not three. Nothing has to be reconciled between competing
  command sets.
- Spec Kit requires Python 3.11+ and the `uv` package manager as a build-time
  dependency of the workflow. It is not a runtime dependency of the application.
- The rejected alternatives are recorded above, so the choice can be assessed on
  its reasoning rather than taken on trust.
- Ruflo's swarm capabilities are genuinely unavailable to this project. If the
  work later grew beyond what a single session can hold, that decision would be
  worth revisiting.
