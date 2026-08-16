# AI-assisted development workflow

This project is built with AI assistance as a deliberate, documented process
rather than ad-hoc prompting. This document describes how the work is actually
produced, so the process can be assessed alongside the code.

The reasoning behind the tooling choice — including the frameworks that were
evaluated and rejected — is recorded in
[ADR 4](adr/0004-spec-driven-ai-workflow.md).

## The loop

Every unit of work passes through five phases. Each leaves something behind in
the repository; a phase with no artifact did not happen.

| Phase | Activity | Artifact |
| --- | --- | --- |
| **Discuss** | Establish what is being built and why; surface alternatives | ADR, or a decision noted in the spec |
| **Plan** | Turn the decision into a specification and a task breakdown | `specs/NNN-feature/spec.md`, `plan.md`, `tasks.md` |
| **Execute** | Implement against the plan | Commits referencing the spec |
| **Verify** | Prove it works and meets the conventions | Passing `composer qa`, tests, audit reports |
| **Ship** | Integrate and document | Merged branch, updated documentation |

The phases repeat per feature. They are not a one-time project schedule.

## Tooling

### Spec Kit

Installed with the Claude integration; provides the commands that drive the Plan
and Execute phases.

| Command | Produces |
| --- | --- |
| `/speckit-constitution` | Project principles in `.specify/memory/constitution.md` |
| `/speckit-specify` | A feature specification — requirements, not implementation |
| `/speckit-clarify` | Structured questions that de-risk an ambiguous spec |
| `/speckit-plan` | Technical approach for the specification |
| `/speckit-tasks` | An ordered, actionable task breakdown |
| `/speckit-implement` | Execution of those tasks |
| `/speckit-analyze` | Consistency check across spec, plan, and tasks |

The important property is that specifications are written **before**
implementation and are committed. A reviewer can compare what was specified
against what was built.

### Project subagents

Three agents in `.claude/agents/` encode this project's rules rather than generic
best practice:

- **`symfony-reviewer`** — reviews a change against `CLAUDE.md`, with the
  layering boundary as its first check.
- **`security-auditor`** — audits authentication, authorisation, uploads, and
  injection surfaces; writes reports to `docs/audits/`.
- **`test-author`** — writes tests in the project's layout, using Foundry
  factories, covering failure paths rather than only the happy path.

Each is instructed to report what it actually finds. An agent that reports a
clean result on code it has not verified is worse than no agent, so the
instructions are explicit that failures are to be reported as failures.

### Conventions as context

`CLAUDE.md` is loaded into every session. It carries the stack, the layering
rule, the coding conventions, and the quality gate — so conventions are enforced
by being present in context, not by being remembered.

## What this does not do

The workflow does not verify itself. Specifications can be written and then
diverged from; agents can produce plausible code that passes review by a reviewer
sharing the same blind spot. The controls against this are mechanical rather than
conversational:

- `composer qa` — PHPStan at level max, coding style, and the test suite. This
  gate is not negotiable by an agent.
- CI runs the same gate on a clean checkout, so nothing depends on local state.
- Tests assert on behaviour, and a failing test is reported rather than adjusted
  to pass.

The final judgement of whether the software is correct rests on the tests and the
audits, not on the workflow that produced it.
