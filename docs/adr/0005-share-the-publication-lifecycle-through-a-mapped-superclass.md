# 5. Share the publication lifecycle through a mapped superclass

- **Status**: Accepted
- **Date**: 2026-08-16
- **Feature**: [specs/001-core-content-model](../../specs/001-core-content-model/spec.md)

## Context

`Article` and `Page` are separate entities by an earlier decision recorded in
[`docs/domain-model.md`](../domain-model.md): an article always has an author and
a date, a page never needs either, and a shared type with a discriminator would
leave one or the other holding meaningless fields.

They nevertheless share a lifecycle exactly. The specification for feature 001
requires it explicitly — user story 4, scenario 5: a page "behaves exactly as an
article does". Both carry a title, a slug, an excerpt, a body, a status, a
publication date and timestamps; both move through draft, published and archived
by the same four acts; both stamp a publication date on the first publish and
never move it afterwards.

The question is where that shared lifecycle lives.

## Decision

Declare it once in `App\Entity\PublishableContent`, an abstract class annotated
`#[ORM\MappedSuperclass]`, holding the shared columns, the four transition
methods, and `isPublished()`. `Article` and `Page` extend it and add only what is
theirs — author, category, tags and lead image for one; parent, menu order and
lead image for the other.

A mapped superclass produces no table of its own. Each subclass gets a complete
table with the shared columns copied into it, no discriminator column and no
join, so the physical schema is exactly what it would have been had the columns
been declared twice.

## Alternatives considered

**Declare the lifecycle in both entities.** Rejected. The specification asks for
identical behaviour, and two copies of a rule diverge the first time one is fixed
and the other is forgotten. Identity maintained by convention is not identity; it
is a convention that will lapse without anyone noticing, and the symptom will be
a page that can be published without a body months later.

**A PHP trait carrying the Doctrine mapping attributes.** This is the closest
call, and arguably the more honest relationship — `Article` is not a *kind of*
publishable content in any hierarchy that anything consumes polymorphically; it
merely shares behaviour, which is what a trait says. Rejected on tooling grounds:
PHPStan's Doctrine extension reasons about mapped superclasses more reliably than
about mapping declared inside traits, and this project runs at level max where
that difference has to be paid for with ignore comments. The constitution
prohibits those, so the design absorbs the constraint instead.

**Single-table inheritance.** Rejected. It puts articles and pages in one table
with a discriminator and forces every article-only column to be nullable — the
exact "meaningless field" problem the two entities were separated to avoid.

**Joined-table inheritance.** Rejected. It adds a join to every read of either
type in order to buy a polymorphism nothing in the specification asks for.

**A `Publication` embeddable value object.** Attractive, because the lifecycle
would become unit-testable with no entity at all. Rejected because publishing is
refused when the title or body is blank (FR-007), so the check needs data the
embeddable does not hold. The rule would end up split across two classes, and a
rule in two places is the thing this decision exists to prevent.

## Consequences

- The shared columns, their nullability and the transition rules are declared
  once. A change to the lifecycle cannot reach one entity and miss the other.
- The identifier is declared on the superclass, which means no association may
  ever target `PublishableContent` — Doctrine does not permit it. Nothing in the
  current design wants to, but a future "recently changed content" listing
  spanning both types would need two queries rather than one.
- `PublishableContent` is a place a field can be added carelessly. A field that
  makes sense for only one of the two belongs in the subclass, and this is a
  review concern with no structural guard behind it.
- Should a third kind of content appear that shares the lifecycle, it extends the
  same class and inherits the rules with no further work.