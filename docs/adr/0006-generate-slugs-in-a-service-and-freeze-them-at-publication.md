# 6. Generate slugs in a service and freeze them at publication

- **Status**: Accepted
- **Date**: 2026-08-16
- **Feature**: [specs/001-core-content-model](../../specs/001-core-content-model/spec.md)

## Context

Every article, page, category and tag is addressed in a URL by a readable name
derived from its title. Three properties are required of that name, and they pull
in different directions:

1. It must be URL-safe, for any title — including accents, punctuation, non-Latin
   script, and titles that reduce to nothing at all.
2. It must be unique within its kind. Uniqueness cannot be decided by looking at
   one row; it needs the table.
3. It must stop changing once readers can link to it. A slug that shifts under a
   published article breaks every existing link and every search result.

Property 1 is pure computation. Property 2 needs the database. Property 3 needs
only the row itself. Putting all three in one place would force the pure part to
carry a database dependency, or the entity to reach into the repository — which
inverts the dependency direction the constitution fixes.

## Decision

Split the work along those lines, and back it with a constraint:

- **`App\Service\Slug\SlugGenerator`** — pure. Turns a title into a base slug with
  `AsciiSlugger` from `symfony/string`, lowercased, hyphen-separated, trimmed of
  leading and trailing hyphens. A title that yields nothing usable falls back to a
  short random token, so a draft can always be saved. No database, no container
  state, unit-testable on its own.
- **`App\Service\Slug\UniqueSlugGenerator`** — takes the base slug and a
  `SluggedRepository`, and appends `-2`, `-3`, … until the slug is free. Each
  entity's repository implements that one-method interface, which is what makes
  uniqueness *per kind* fall out with no special-casing: an article and a page may
  both be `hello-world`.
- **A `UNIQUE` index on every `slug` column** — the backstop for two requests that
  generate the same slug concurrently and neither of which can see the other. The
  resulting constraint violation is a genuine conflict for the caller to retry,
  not a case to be swallowed.
- **`PublishableContent::assignSlug()` throws `SlugIsFrozen`** once `publishedAt`
  is set. There is no `setSlug()` anywhere.

## Alternatives considered

**A Doctrine `prePersist`/`preUpdate` listener doing the whole job.** Rejected:
`preUpdate` cannot safely query other entities, which is precisely what
uniqueness requires.

**Generating the slug inside the entity's title setter.** Rejected: it pulls a
repository into the domain layer and inverts the dependency direction that
constitution principle I exists to protect.

**A random suffix instead of a counter.** Unique, and rejected on readability —
`hello-world-a8f3` reads like a mistake where `hello-world-2` reads like a second
article on the same subject.

**A database trigger.** Rejected: invisible from the application and untestable
from PHPUnit.

**Freezing the slug at creation rather than at publication.** Rejected: a draft
title is routinely wrong on the first attempt, and nobody can link to a draft, so
there is nothing to protect yet.

## Consequences

- The interesting slug cases — accents, punctuation, empty results — are tested
  with no database at all, which makes them cheap enough to test exhaustively.
- Uniqueness is enforced twice, and the two failures mean different things. The
  service produces the *specified* outcome (a distinct slug); the index catches
  the race the service cannot see and reports a conflict.
- **A known gap, recorded rather than left to be discovered.** The entity
  guarantees a slug *stops changing* after publication, because that decision
  needs no other row. It cannot guarantee a slug is *regenerated* when a draft's
  title changes, because uniqueness needs the database — that half of FR-012 is a
  service behaviour, and a caller that sets a title without going through the
  service leaves the slug stale. This is a real gap against the specification's
  SC-001 ("none of the rules can be bypassed by any caller"). It is accepted for
  feature 001, where the only callers are tests and fixtures, and it closes when
  the admin layer gives content editing a single entry point.
- A published slug cannot be changed at all in this feature. Renaming published
  content with a redirect is a legitimate future need, and it will need its own
  decision — an alias table, so old links keep working — rather than a relaxation
  of this one.