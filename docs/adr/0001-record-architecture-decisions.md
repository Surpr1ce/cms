# 1. Record architecture decisions

- **Status**: Accepted
- **Date**: 2026-08-16

## Context

This project is developed with heavy AI assistance across many sessions. An agent
that joins later has the code and the git history, but neither of those records
*why* a choice was made or which alternatives were rejected. Without that, later
sessions re-litigate settled questions or silently reverse them.

The same problem applies to the human reviewer assessing this work: the finished
code shows what was built, not the reasoning that produced it.

## Decision

Every architecturally significant decision is recorded as a numbered Architecture
Decision Record in `docs/adr/`, following Michael Nygard's format: context,
decision, consequences.

A decision is architecturally significant if reversing it later would require
changing more than one layer, or if a reasonable engineer would ask "why is it
done this way?".

Records are immutable once accepted. A decision that no longer holds is not
edited — a new record supersedes it, and the old one is marked as superseded with
a link forward.

## Consequences

- The reasoning behind the codebase survives beyond the session that produced it.
- Agents are instructed by `CLAUDE.md` to read relevant ADRs before changing a
  decision they touch.
- There is a small ongoing cost: each significant decision needs a short document
  written at the time it is made, not retrofitted afterwards.
- Records that were written and later superseded remain in the repository. The
  history of rejected approaches is part of the deliverable, not clutter.
