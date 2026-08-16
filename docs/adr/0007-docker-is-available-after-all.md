# 7. Docker is available after all; compose.yaml is verified

- **Status**: Accepted
- **Date**: 2026-08-17
- **Supersedes**: [ADR 3](0003-postgresql-natively-instead-of-docker.md)

## Context

[ADR 3](0003-postgresql-natively-instead-of-docker.md) recorded that Docker could
not be used on this machine: WSL2 was not installed, Docker Desktop would not
start, and `compose.yaml` was therefore retained but untested. PostgreSQL was
installed natively instead.

That constraint no longer holds. As of today the machine reports:

```
docker --version   → Docker version 29.7.2
wsl --status       → Default Distribution: docker-desktop, Default Version: 2
```

The claim was verified rather than taken from the version string. `docker compose
up -d database` created the network, the volume and the container; the container
reached a healthy state; and `psql` inside it answered:

```
PostgreSQL 16.15 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0), 64-bit
```

So `compose.yaml` works. The word "untested" in ADR 3 and in `docs/status.md` was
accurate when written and is no longer accurate now.

Accepted records are immutable under the constitution, so ADR 3 is superseded by
this record rather than edited.

## Decision

Record that Docker is available and that `compose.yaml` is verified.

**Keep natively-installed PostgreSQL as the default for development on this
machine**, and treat the compose stack as a proven, supported alternative.

Two reasons for not switching:

1. The native instance holds the migrated `app` and `app_test` databases and the
   development fixtures. Switching means re-running migrations and fixtures
   against an empty volume for no gain that the project can currently point at.
2. `compose.override.yaml` publishes the database on an ephemeral host port
   (`ports: - "5432"` with no host side), so `DATABASE_URL` would have to be
   rewritten after every `up`. Making it deterministic means pinning a port,
   which is a change to a Flex recipe file that should be made deliberately when
   somebody actually needs it — not as a side effect of noticing that Docker
   started working.

Neither reason is permanent. A contributor who prefers the container path can use
it today; the commands are in `docs/setup.md`.

## Alternatives considered

**Switch development to the compose stack now.** The stronger option on
reproducibility grounds, and the one to take the moment a second machine or a
second contributor joins. Rejected for now on the two grounds above: it is churn
with no present benefit, and the port question deserves its own small decision.

**Leave ADR 3 alone and say nothing.** Rejected outright. `docs/status.md`
carried "Docker is unavailable on the development machine" as a known constraint,
and a constraint that has quietly stopped being true is worse than one that was
never recorded — a reader has no reason to check it.

**Edit ADR 3 in place.** Prohibited by the constitution: accepted records are
superseded, never rewritten, so that a reader can tell a considered reversal from
a quiet one.

## Consequences

- `compose.yaml` and `compose.override.yaml` are no longer "retained but
  untested". They are tested, and `docs/setup.md` documents how to use them.
- CI already runs PostgreSQL 16 in a service container, so local-and-CI parity is
  now achievable on this machine too, should it ever be in question.
- Anything in the documentation that still asserts Docker is unavailable is now
  wrong. `docs/status.md` and `CLAUDE.md` are corrected in the same change as
  this record.
- The two PostgreSQL instances do not conflict: the container publishes on an
  ephemeral host port, the native service holds 5432.
- If the compose path becomes the default later, that is a further decision with
  its own record, including the fixed host port.
