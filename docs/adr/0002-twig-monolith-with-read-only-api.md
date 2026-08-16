# 2. Twig monolith with a read-only JSON API

- **Status**: Accepted
- **Date**: 2026-08-16

## Context

The assignment requires Symfony. Twig is not strictly mandated, which leaves the
presentation architecture open. Three options were considered:

1. **Pure Twig monolith** — classic server-rendered MVC. Fastest to complete,
   but demonstrates nothing about how content would be consumed by other clients.
2. **Fully headless** — API Platform backend plus a separate Next.js or Nuxt
   frontend. Architecturally current, but it is two projects, and the delivery
   window for this work is one week. The realistic outcome is two half-finished
   applications instead of one complete one.
3. **Twig monolith with a read-only API on top of a shared domain layer.**

The deciding constraint is the schedule, not a technical preference. A headless
split is a legitimate architecture; it is simply not one that can be finished
well in the time available alongside documentation, tests, and audits.

## Decision

Build a Twig-rendered monolith, but structure the domain so that presentation is
a consumer rather than an owner of it. Expose the same content through a
read-only JSON API served by API Platform.

The enforceable form of this rule: no class under `src/Entity/` or `src/Service/`
may reference `Request`, `Response`, Twig, or session state. Controllers are the
only translation point between HTTP and the domain.

## Consequences

- The requirement to use Symfony is met, and Twig carries the user-facing work.
- Headless readiness is demonstrable rather than asserted — `/api/articles`
  returns the same content the Twig templates render, from the same services.
- Adding a JavaScript frontend later requires no change to the domain layer.
- The API is read-only. Writes go through the admin, which means the API is not a
  complete headless CMS interface. This is a deliberate scope limit, recorded
  here so it is not mistaken for an oversight.
- API Platform adds a substantial dependency for a comparatively small surface.
  Accepted because it also generates the OpenAPI documentation, which would
  otherwise have to be written by hand.
