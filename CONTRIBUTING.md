# Contributing

## Before you start

Read [`CLAUDE.md`](CLAUDE.md). It is the working contract for this repository and
applies to human and AI contributors alike.

## Workflow

Work proceeds in five phases — Discuss, Plan, Execute, Verify, Ship — described
in [`docs/ai-workflow.md`](docs/ai-workflow.md). In short:

1. Branch from `master`: `git switch -c feat/short-description`.
2. If the change involves a decision worth explaining, write an ADR in
   `docs/adr/` first.
3. For anything larger than a fix, produce a specification with
   `/speckit-specify` before writing code.
4. Implement, keeping one concern per commit.
5. Run `composer qa` and make it pass.
6. Open a pull request. CI must be green.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/), imperative mood, no
trailing period:

```
feat(article): add publishing workflow
fix(media): reject uploads whose MIME type does not match content
docs(adr): record the decision to run PostgreSQL natively
test(article): cover unauthorised edit attempts
```

Reference the specification when one exists.

## Quality gate

```bash
composer qa
```

This runs coding style, Rector in dry-run mode, PHPStan at level max, and the
test suite. All four must pass.

Do not lower the PHPStan level, add a baseline, or delete a failing test to get
through the gate. If a check is wrong, fix the check deliberately and say why in
the commit message.

## Pull requests

A pull request describes what changed and why, links the specification or ADR,
and states what was verified. If part of the work is incomplete, say so
explicitly — an unstated gap is worse than a stated one.

Run the `symfony-reviewer` agent before requesting review; it checks the layering
boundary and Doctrine usage that a human reviewer commonly reads past.

## Documentation

Documentation changes ship with the code they describe, in the same pull request.
When a change makes something in `docs/status.md` inaccurate, update it in the
same commit — a status file that has drifted is worse than none.
