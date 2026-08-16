# Phase 0 Research: Core Content Model

**Feature**: `001-core-content-model` | **Date**: 2026-08-16

The specification left no `[NEEDS CLARIFICATION]` markers, so this document is not
a list of unknowns to resolve. It is the record of *how* each requirement will be
met, and what was rejected — which is the part that is invisible in the finished
code and expensive to reconstruct later.

Decisions 1 and 5 change more than one layer if reversed and are therefore
promoted to ADRs (0005 and 0006) as required by constitution principle V.

---

## 1. Article and Page share their lifecycle through a mapped superclass

**Decision**: `abstract class PublishableContent` annotated
`#[ORM\MappedSuperclass]`, holding `id`, `title`, `slug`, `excerpt`, `content`,
`status`, `publishedAt`, `createdAt`, `updatedAt`, and the four transition
methods. `Article` and `Page` extend it and add only what is theirs.

**Rationale**: US4 scenario 5 requires page behaviour to be *identical* to article
behaviour. A mapped superclass makes that identity structural rather than
maintained by hand: the columns, their nullability and the transition rules are
declared once, and each subclass still gets its own table with no discriminator
column and no join.

**Alternatives considered**:

| Option | Why rejected |
| --- | --- |
| Duplicate the lifecycle in both entities | Two copies of a rule diverge the first time one is fixed and the other is forgotten. The specification asks for identical behaviour; identity by convention is not identity. |
| A PHP trait carrying the Doctrine attributes | Works, and expresses "shares behaviour" without claiming a hierarchy — which is arguably the more honest relationship. Rejected on tooling grounds: PHPStan's Doctrine extension reasons about mapped superclasses more reliably than about mapping declared in traits, and at level max that difference is paid for in ignore comments the constitution forbids. |
| Single-table inheritance | Puts articles and pages in one table with a discriminator and forces every article-only column to be nullable, which is exactly the "meaningless field" problem `docs/domain-model.md` separated them to avoid. |
| Joined-table inheritance | Adds a join to every read of either type to buy a polymorphism nothing in the specification asks for. |
| A `Publication` embeddable value object | Attractive: the lifecycle becomes unit-testable with no entity at all. Rejected because the publish rule needs the *title and body* to decide (FR-007), so the check would sit outside the object that owns the state, splitting one rule across two classes. |

**ADR**: 0005 — "Share the publication lifecycle through a mapped superclass".

---

## 2. Status is a backed enum, mapped natively

**Decision**: `enum ContentStatus: string { case Draft; case Published; case Archived; }`,
mapped with `#[ORM\Column(enumType: ContentStatus::class)]`. Stored as a
`VARCHAR(16)`, not a PostgreSQL `ENUM` type.

**Rationale**: the enum makes an invalid status unrepresentable in PHP, which is
most of what FR-001 asks for. Storing it as a string rather than a native
PostgreSQL enum keeps `doctrine:migrations:diff` able to generate a clean
migration — Doctrine does not model native enum types, so a native type produces
a migration that fights the diff on every subsequent run.

**Alternatives considered**: integer-backed enum (unreadable in a database
console, and the mapping between number and meaning lives only in code);
`smallint` column with class constants (pre-8.1 style, no exhaustiveness
checking); PostgreSQL native `ENUM` (rejected above).

---

## 3. Transitions are methods that refuse the forbidden case loudly

**Decision**: `publish()`, `unpublish()`, `archive()`, `restore()` on
`PublishableContent`. There is no `setStatus()`. An attempt to make a transition
the state machine does not allow throws `InvalidStatusTransition`; an attempt to
publish content missing a title or body throws `ContentNotPublishable`. Both
extend a common `DomainException`.

Permitted transitions, from FR-004:

```
                 publish()
   ┌────────────────────────────────┐
   ▼                                │
 draft ◀────unpublish()──── published ────archive()───▶ archived
   │                                                       │
   └──────────────────archive()────────────────────────────┤
   ▲                                                       │
   └──────────────────restore()────────────────────────────┘
```

`restore()` returns archived content to **draft**, never straight to published —
bringing something back and making it visible again are two decisions, and the
person doing the first has not necessarily made the second.

**Rationale**: US1 scenario 7 requires publishing already-published content to be
"refused as invalid rather than silently ignored". An exception is the only
outcome a test can assert on unambiguously; a silent no-op and a success look
identical from outside.

**Alternatives considered**: returning `bool` (callers ignore return values, and
PHPStan cannot force them not to); a separate state-machine component such as
`symfony/workflow` (a configuration file, a container service and a new
dependency to express five transitions that fit in four short methods).

---

## 4. Time is passed in, never read inside the domain

**Decision**: entity methods that need the current time take it as a parameter —
`publish(\DateTimeImmutable $now)`. Services that call them take
`Symfony\Component\Clock\ClockInterface` through the constructor. `createdAt` is
set in the entity constructor from a passed-in value; `updatedAt` is maintained by
a Doctrine `#[ORM\PreUpdate]` lifecycle callback.

**Rationale**: SC-005 requires proving that a publication date is unchanged across
any number of unpublish/publish cycles. That test is exact and instant when the
time is an argument, and becomes a sleep-and-hope test when the entity reads the
clock itself. `symfony/clock` is already installed, so services get a mockable
clock at no cost.

**Alternatives considered**: `new \DateTimeImmutable()` inside the entity
(untestable without freezing global time); a nullable parameter defaulting to
"now" (offers a convenient way to bypass the very thing being tested, and callers
take it); `ClockInterface` injected into the entity (entities are constructed by
Doctrine, not by the container — this does not work without a listener that
injects it, which is machinery in exchange for nothing).

The one framework coupling accepted here is the `#[ORM\PreUpdate]` callback for
`updatedAt`. Doctrine is persistence, not delivery, so principle I is untouched,
and the alternative — remembering to call `touch()` at every mutation site — is a
rule enforced by discipline, which is the kind this project is trying to avoid.

---

## 5. Slug generation is split three ways, and the freeze rule is honestly limited

**Decision**:

- `SlugGenerator` — pure, no database. `AsciiSlugger` from `symfony/string`,
  lowercased, hyphen-separated, trimmed of leading and trailing hyphens. A title
  that yields nothing usable falls back to a short random token (`n7k2p9q4`), so
  a draft can always be saved (spec, Edge Cases).
- `UniqueSlugGenerator` — takes the base slug and a `SluggedRepository`, and
  appends `-2`, `-3`, … until `existsWithSlug()` says no. Each entity's repository
  implements that one-method interface, which is what keeps uniqueness *per kind*
  (FR-010) without any special-casing.
- A `UNIQUE` index on every `slug` column — the backstop when two requests
  generate the same slug concurrently and neither sees the other. The resulting
  constraint violation is a genuine conflict for the caller to retry, not a case
  to be swallowed.

The freeze rule (FR-012) lives on the entity: `assignSlug()` throws
`SlugIsFrozen` once `publishedAt` is set.

**Honest limitation, stated because it is easy to overlook**: the entity can
guarantee the slug *stops changing* after publication, because that decision needs
no other row. It cannot guarantee the slug is *regenerated* when a draft's title
changes, because uniqueness needs the database. That half of FR-012 is a service
behaviour, and a caller that sets a title without going through the service will
leave the slug stale. This is a real gap against SC-001 ("none of the rules can be
bypassed by any caller"). It is accepted for this feature — there are no callers
yet other than tests — and it closes when the admin layer arrives with a single
entry point for editing. The task list carries it as an explicit note rather than
leaving it for someone to discover.

**Alternatives considered**: a Doctrine `prePersist`/`preUpdate` listener doing
the whole job (`preUpdate` cannot safely query other entities, which is exactly
what uniqueness requires); generating the slug inside the entity setter
(pulls a repository into the domain and inverts the dependency direction the
constitution fixes); appending a random suffix instead of a counter (unique, but
`hello-world-a8f3` reads like a mistake where `hello-world-2` reads like a second
article); a database trigger (invisible to the application and untestable from
PHPUnit).

**ADR**: 0006 — "Generate slugs in a service and freeze them at publication".

---

## 6. Deletion rules are enforced twice — in a service and in the schema

**Decision**: each rule gets a service that produces a good error or the correct
reshaping, *and*, where the rule is expressible as a constraint, a foreign key
that makes the forbidden outcome impossible.

| Rule (FR) | Service | Database |
| --- | --- | --- |
| Deleting a category leaves articles uncategorised (FR-016) | `CategoryDeleter` clears the in-memory association | `article.category_id` → `ON DELETE SET NULL` |
| Deleting a category re-parents its children (FR-016) | `CategoryDeleter` — the only place this can happen | none possible |
| Deleting a label leaves articles (FR-017) | none needed | join table → `ON DELETE CASCADE` |
| Deleting a page with children is refused (FR-018) | `PageDeleter` throws `PageStillHasChildren` | `page.parent_id` → `ON DELETE RESTRICT` |
| Deleting an owner is refused (FR-028) | `UserDeleter` throws `UserStillOwnsContent` | `article.author_id`, `media.uploaded_by_id` → `ON DELETE RESTRICT` |
| Deleting a file clears lead images (FR-024) | `MediaDeleter` clears the in-memory association | `*.featured_image_id` → `ON DELETE SET NULL` |

**Rationale**: the two layers fail differently and both failures matter. The
service gives a caller a message it can act on ("this account still authors 12
articles"); the constraint gives the *system* a guarantee that survives a future
caller who never heard of the service. Where only one is possible, that is stated
above rather than hidden.

The in-memory clearing is not redundant with `ON DELETE SET NULL`: the database
updates the row, but an `Article` already loaded in Doctrine's identity map keeps
pointing at the deleted `Media` until the entity manager is cleared. Leaving that
to chance is a defect waiting for its first reproduction.

**Alternatives considered**: `cascade: ['remove']` in the ORM mapping (deletes the
articles — the exact opposite of FR-016); `orphanRemoval` (same problem);
constraints only (cannot re-parent, and turns a business rule into a
`ForeignKeyConstraintViolationException` with a message about a constraint name);
services only (one forgetful caller and the rule is gone).

---

## 7. Circular hierarchies are refused by walking the chain

**Decision**: `Category::setParent()` and `Page::setParent()` walk the ancestor
chain and throw `HierarchyWouldBeCircular` if they meet themselves.

**Rationale**: the check needs only already-loaded associations, so it belongs on
the entity, and the walk is bounded by the depth of a menu — single digits in any
real site.

**Alternatives considered**: a recursive CTE check in the database (correct and
far more machinery than the depth warrants); a materialised path or nested-set
column (a performance structure for read-heavy deep trees, bought before there is
a tree); no check at all (an infinite loop the first time a template renders
breadcrumbs).

---

## 8. Entity shape is dictated by PHPStan level max, not worked around

**Decision**: identifiers are declared `private ?int $id = null` with
`getId(): ?int`. Every collection property is initialised to an
`ArrayCollection` in the constructor. Every array-typed property carries a
`@var list<string>`-style docblock. Every association is typed and nullable
exactly as the column is.

**Rationale**: `phpstan.neon.dist` sets `checkUninitializedProperties: true`, so
the conventional `private int $id;` is an error — the property is genuinely
unset until Doctrine assigns it, and the analyser is right. Constitution
principle III forbids lowering the level or adding a baseline, so the design
absorbs the constraint instead. `getId(): ?int` returning null for an unpersisted
entity is not a workaround; it is accurate.

**Alternatives considered**: `#[\AllowDynamicProperties]`-style escapes and
targeted ignores (prohibited); generating identifiers in the constructor as UUIDs
so they are never null (a real option that would remove the nullability entirely,
but it changes every primary key in the system to `uuid` and contradicts
`docs/domain-model.md`, which specifies `int` — a change of that size needs its
own discussion, not a side effect of a static-analysis setting).

---

## 9. Repositories return typed lists and own the definition of "visible"

**Decision**: every query method returns `array` with a `@return list<Article>`
docblock and never a `QueryBuilder`. The published scope is a private helper
inside each repository, used by every public method that claims to return
published content. Ordering for listings is `published_at DESC, id DESC`.

**Rationale**: FR-031 exists so that no caller reimplements "visible". If the
scope is a private method that public methods route through, the API and the Twig
site cannot disagree, which is the whole argument of
[ADR 2](../../docs/adr/0002-twig-monolith-with-read-only-api.md). The `id DESC`
tiebreak makes ordering total — without it, two items published in the same second
swap places between requests and pagination silently repeats or skips a row.

**Alternatives considered**: a Doctrine filter applied globally (invisible at the
call site, and it would also hide content from the admin screens that must see
drafts); returning `QueryBuilder` for callers to extend (explicitly forbidden by
`CLAUDE.md`, and it is how the published scope escapes).

---

## 10. Domain exceptions live in `src/Exception/`

**Decision**: a `DomainException` base class extending PHP's `\DomainException`,
with one subclass per refused rule, in `src/Exception/`.

**Rationale**: distinct classes let a test assert on the *rule* that was broken
rather than on a message string, and let the future admin layer map each to a
different response without parsing text. `CLAUDE.md` does not list this directory,
so `CLAUDE.md` is updated in the same change rather than left inaccurate.

**Alternatives considered**: `\InvalidArgumentException` with messages
(assertions become string matching, which breaks on rewording); exceptions nested
under `src/Entity/Exception/` (services throw some of them too, so the location
would be wrong for half of them).

---

## 11. Foundry factories live in `src/Factory/`

**Decision**: `src/Factory/`, one class per entity, following Foundry 2's
class-based style, and included in PHPStan's analysis.

**Rationale**: `src/Story/AppStory.php` and `src/DataFixtures/AppFixtures.php`
already exist and are production code that loads development data; they need the
factories, so the factories cannot live under `tests/`. This is Foundry 2's own
default layout.

The cost is honest: test-support code sits in `src/` and is analysed at level max.
That is a price rather than a benefit, and it is paid to avoid the worse outcome —
fixtures and tests building the same entities two different ways.

**Alternatives considered**: `tests/Factory/` with fixtures duplicating the setup
(two sources of truth for what a valid article looks like); factories in `tests/`
added to the production autoloader (ships test code in the production autoload map).

---

## 12. Stored filenames are generated from randomness, not from the upload

**Decision**: `StoredFilenameGenerator` returns
`bin2hex(random_bytes(16))` plus an extension derived from the *detected* MIME
type via a small allow-list. The supplied name never contributes to it. `Media`
takes the generated name through its constructor and offers no way to change it.

**Rationale**: FR-021 and US5 scenario 1 require that `../../evil.php` cannot
influence the stored name. Deriving the name from randomness rather than from
input makes traversal and executable extensions unreachable rather than filtered,
and a filter is only as good as its last review.

`symfony/uid` is present in `vendor/` but only as a transitive dependency;
`random_bytes` needs nothing and is already available. Adding `symfony/uid` to
`composer.json` for one call would be a dependency taken on for cosmetics.

**Alternatives considered**: slugifying the original name (readable, and one
normalisation bug away from a traversal); a hash of the file contents
(deduplicates uploads, but two pieces of content then share one row and deleting
one deletes the other's image — worth doing later, deliberately, not as a side
effect); `uniqid()` (time-based, guessable, and not a security primitive).

---

## 13. Alternative text is required at the point of use, not at cataloguing

**Decision**: `Media` accepts a null `altText`. `Article::setFeaturedImage()` and
`Page::setFeaturedImage()` throw `MediaMissingAltText` when the file has none.

**Rationale**: this is the specification's assumption made concrete. Cataloguing
an upload must not fail on a field the uploader has not reached yet, but content
must not go out with an unlabelled image. Putting the check at the point of use
satisfies both, and it is exactly what US5 scenario 4 asserts.

**Alternatives considered**: requiring alt text on `Media` itself (blocks the
upload screen on a field that belongs to the editing screen); a validation
constraint on the article (runs only when something calls the validator, so the
rule would be absent in every path that does not).

---

## 14. The test suites named in the documentation are made to exist

**Decision**: add `unit`, `integration` and `functional` test suites to
`phpunit.dist.xml`, keeping a default suite that runs all three.

**Rationale**: `docs/testing.md` documents `vendor/bin/phpunit --testsuite unit`,
and `phpunit.dist.xml` currently defines a single suite named "Project Test
Suite", so that command fails today. The documentation describes the intent
correctly and the configuration has not caught up. This feature is the first to
add tests, so it is the point at which the gap becomes real rather than
theoretical.

**Alternatives considered**: correcting `docs/testing.md` to match the
configuration instead (the split by cost is deliberate and useful — a developer
wanting fast feedback should be able to run the database-free tests alone).

---

## Summary of the resulting work

| Area | Count |
| --- | --- |
| Entities (incl. abstract superclass) | 7 |
| Enums | 1 |
| Repositories (incl. 1 interface) | 7 |
| Services | 7 |
| Domain exceptions (incl. base) | 8 |
| Foundry factories | 6 |
| Migrations | 5 — one per phase that adds tables or columns, so each user story is testable against a real schema; see `tasks.md`, "A note on migrations" |
| ADRs | 2 (0005, 0006) |
| Documentation updates | `CLAUDE.md`, `docs/domain-model.md`, `docs/status.md`, `phpunit.dist.xml` |

Nothing above requires a new Composer dependency.