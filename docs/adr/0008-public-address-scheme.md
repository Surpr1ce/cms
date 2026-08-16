# 8. Public address scheme, with standalone pages at the root

- **Status**: Accepted
- **Date**: 2026-08-17
- **Feature**: [specs/002-public-website](../../specs/002-public-website/spec.md)

## Context

Feature 002 puts the first addresses of this project on the public internet. A
link that exists in the world cannot be taken back: changing an address later
breaks every bookmark, every inbound link and every search result pointing at it,
and this project deliberately has no redirect mechanism —
[ADR 6](0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md)
froze slugs at publication precisely so that addresses stop moving.

Four kinds of content need addresses: articles, standalone pages, sections and
labels. Their slugs are unique within a kind but not across kinds, so an article
and a page may both be `hello-world`. Something has to keep them apart.

## Decision

```
/                    the home listing
/articles/{slug}     an article
/sections/{slug}     a section
/topics/{slug}       a label
/{slug}              a standalone page
```

Three of the four are prefixed. **Standalone pages sit at the root**: `/about-us`
rather than `/pages/about-us`.

Every `{slug}` carries the route requirement `[a-z0-9]+(?:-[a-z0-9]+)*`, derived
from `App\Entity\Slug::PATTERN` rather than written again, so an address no
content could ever have is refused by the router.

The page route is registered with `priority: -100` so the prefixed routes are
matched first, and `PageController::RESERVED` refuses the first segments the site
already uses.

## Alternatives considered

**Prefix pages too**, at `/pages/{slug}`. The safe option: no catch-all, no
reserved list, no future prefix ever conflicting. Rejected on reader-facing
grounds. Standalone pages are the content most often linked from outside a site —
an "About" or a "Privacy" link in somebody else's footer — and `/about-us` is the
address a person expects. The safety this gives up is recovered by the priority
and the reserved list, both of which are tested.

**Put articles at the root too**, `/hello-world` for everything. Rejected: article
and page slugs are unique per kind, not globally, so two pieces of content could
legitimately claim the same address and one of them would become unreachable.
Making slugs globally unique to allow this would mean changing the content model
to serve a URL preference.

**Date-based article addresses**, `/2026/08/hello-world`. Common, and it makes
collisions almost impossible. Rejected because it embeds the publication date in
the address, and this project already decided that a publication date can be
wrong at first — it is stamped at the first publish and never moves. An address
that encodes a fact is an address that becomes wrong when the fact does.

**`/tags/{slug}` rather than `/topics/{slug}`.** A coin toss. `topics` reads as
something a reader is looking for, `tags` reads as something the software has.
Recorded only because a reviewer will notice the model calls it a `Tag`.

## Consequences

- A page can never be called `articles`, `sections`, `topics`, `api` or `admin`.
  `PageController::RESERVED` is the single place that list lives.
- **Any future root-level prefix inherits a cost.** Adding `/search` means no page
  may be called `search`, and if one already is, its address has to change — which
  the slug freeze forbids. Whoever adds the next prefix must add it to `RESERVED`
  first and check no published page holds it. This is the real price of the
  decision and it is paid later, not now.
- `/api` is on the reserved list and is already answered by API Platform. A test
  asserts a page cannot shadow it, and asserts it by checking the content rather
  than the status, because that address legitimately returns 200 from somebody
  else.
- The slug requirement means a malformed address never reaches a controller, so
  no controller has to defend against one.
- Reversing this decision would change routes, every template that generates a
  link, and every address already published. That is why it is a record rather
  than a comment.
