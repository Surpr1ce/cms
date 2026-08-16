# 3. PostgreSQL installed natively instead of via Docker

- **Status**: Accepted
- **Date**: 2026-08-16

## Context

The Symfony skeleton ships a `compose.yaml` that runs PostgreSQL 16 in Docker,
and Docker was the intended development database.

Docker Desktop was installed but failed to start on the development machine with
"Virtualization support not detected". Diagnosis showed that the Windows
Subsystem for Linux is not installed at all (`wsl --status` reports it as
missing), and Docker Desktop on Windows Home requires the WSL2 backend.

Repairing this requires `wsl --install` with administrator rights followed by a
reboot, and it is not certain that hardware virtualisation is enabled in firmware
either. With a one-week delivery window, spending the first hours on host
configuration was judged the wrong trade.

## Decision

Install PostgreSQL 16 natively on the development machine and point
`DATABASE_URL` at it.

`compose.yaml` stays in the repository unchanged, so contributors with a working
Docker installation can use it. Continuous integration uses a Postgres service
container, which is unaffected by the local situation.

## Consequences

- Development is unblocked immediately, with no reboot and no administrator
  intervention.
- The database engine and version are identical to the Docker setup and to CI, so
  no behavioural difference is introduced — this is a change in how Postgres is
  started, not in what is used.
- The project no longer has a single documented "run this one command" setup path
  on Windows Home. `README.md` documents both routes.
- If the development machine later gains WSL2, switching back to Docker requires
  only a change to `DATABASE_URL`.
