# Feature 019 — every listing bounded

## Why

The public side of this site has been paginated since feature 002: the home
listing, sections, labels and search all fetch one page and one extra row. The
administration side never was. Every screen an editor uses every day loads its
whole table:

| Screen | Today |
| --- | --- |
| `/admin/articles` | `findBy([])` — every article, then a voter per row |
| `/admin/pages` | `findBy([])` — every page |
| `/admin/manage/accounts` | `findBy([])` — every account |
| `/admin/manage/labels` | `findAllOrdered()` — every label |
| `/admin/manage/sections` | `findAllOrdered()` — every section |
| `/admin/media` | `findRecent(100)` — a silent cap, which is worse than either |
| `/sitemap.xml` | `findAll()` — every published article and page |

None of this is felt on a development site with twelve articles, which is exactly
why it survived eighteen features. It is felt on a real one, and the first person
to feel it is an editor who cannot open their own article list.

The article list is the interesting one and the reason this is a feature rather
than a chore. It cannot simply be paginated: it loads every article and then asks
`ArticleVoter` about each, so cutting the query into pages would produce pages of
uneven and unpredictable size — twenty rows fetched, six shown. **The visibility
rule has to move into the query**, and then the query and the voter have to be
kept saying the same thing.

## Scope

### US1 — the administration listings are paginated (P1)

**Acceptance**

1. `/admin/articles`, `/admin/pages`, `/admin/media`, `/admin/manage/accounts`
   and `/admin/manage/labels` each fetch one page and offer previous/next through
   the same component the public listings use.
2. No listing's query count grows with the number of rows it shows — the same
   property SC-007 asserts for the public side.
3. A page beyond the end is empty rather than an error, and the page number is
   normalised by the same `Paginator` the rest of the site uses.

### US2 — the article list shows exactly what the voter allows (P1)

**Acceptance**

1. `ArticleRepository` answers a page of articles for a given viewer, and what it
   returns is **identical** to filtering every article through
   `ArticleVoter::VIEW` — for an author, an editor, an administrator, and an
   account holding no roles at all.
2. That identity is asserted by a test that runs both and compares them, so the
   query and the voter cannot drift apart silently.
3. An author sees published work and their own drafts, and no other author's
   drafts — through the query, not through a filter applied afterwards.

### US3 — the sitemap is bounded (P2)

**Acceptance**

1. The sitemap emits at most 50,000 addresses, which is the limit the sitemap
   protocol sets, rather than however many exist.
2. Reaching that limit is a decision recorded in the code, not a truncation
   nobody can see.

## Out of scope

- **Numbered pages.** Previous/next only, as everywhere else: a total needs a
  second `COUNT` per listing, which is the query SC-007 exists to avoid.
- **Sections.** The screen renders a tree, and a tree cut across a page boundary
  is not a tree — a subsection would appear with no parent above it. Sections are
  structural and few by nature; this is recorded rather than paginated.
- **Sorting and filtering in the administration lists.** Real, wanted, and a
  feature of its own.
- **A sitemap index** splitting across several files, which is what a site past
  50,000 addresses actually needs.

## Success criteria

- **SC-001** No route in the application loads an unbounded number of rows.
- **SC-002** The article list and `ArticleVoter` agree for every combination of
  roles and ownership, proven by comparison rather than by inspection.
- **SC-003** Every paginated administration screen issues the same number of
  queries whatever the page holds.

## Afterwards — SC-001 was written wider than this feature was built

Recorded here rather than quietly narrowed, because the criterion is the claim
somebody will read next.

SC-001 says *no route* loads an unbounded number of rows. What was built bounds
the routes the table at the top enumerates, plus the sitemap. The architecture
audit run before the release found four reads it does not cover, all of them
reachable from a route:

- `/api/pages` and `/api/sections` — `PageProvider` and `CategoryProvider` return
  every row; `ArticleProvider` shows the paginated shape they should have.
- The article and page edit forms — five `query_builder` closures with no limit,
  so the media library, every page, every section and every label are read whole
  two clicks from the screens this feature paginated.
- `/{slug}` — `PageRepository::findPublishedChildrenOf()` is unbounded, which is
  fine for a menu and is not what a route should rely on.

They are tracked in [`docs/status.md`](../../docs/status.md) under known gaps.
None is a regression this feature introduced; all four are the same debt it was
written to pay off, in places its table did not name.
