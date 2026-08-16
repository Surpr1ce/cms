---

description: "Task list for feature 001 — core content model"
---

# Tasks: Core Content Model

**Input**: Design documents from `/specs/001-core-content-model/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/domain-api.md`

**Tests**: Included, and not optional here — FR-032 and FR-033 make them part of
the feature, and constitution principle IV requires a test proving every invariant
is refused when violated. Test tasks are written **before** the implementation
they cover within each phase, and are expected to fail until it lands.

**Organization**: Grouped by user story, so each phase is a complete increment
whose proof can be run on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on incomplete work)
- **[Story]**: The user story from `spec.md` this task serves
- Every task names the exact file it touches

## Path Conventions

Single Symfony project: `src/`, `tests/`, `migrations/`, `docs/` at repository
root, as fixed by `CLAUDE.md` and detailed in `plan.md`.

## A note on migrations

`plan.md` originally estimated a single migration. Splitting the work by user
story makes that impossible without giving up independent testability — US1 cannot
be verified against a database whose tables do not exist yet. This list therefore
produces **five** migrations, each generated with `doctrine:migrations:diff` at the
end of the phase that introduces its tables or columns.

That is the honest trade and it is the better one: five diff-generated migrations
are how the schema actually evolved, and none of them is ever edited afterwards.
`plan.md` and `research.md` have both been corrected to say five rather than left
claiming one.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Make the repository able to hold this feature — test suites that the
documentation already promises, directories that `CLAUDE.md` does not yet list,
and the two decision records the constitution requires.

- [x] T001 Add `unit`, `integration` and `functional` test suites to `phpunit.dist.xml`, keeping a default suite that runs all three (see `research.md` decision 14 — `docs/testing.md` documents `--testsuite unit`, which fails today)
- [x] T002 [P] Add `src/Exception/` and `src/Factory/` to the architecture tree in `CLAUDE.md`, each with a one-line description of what belongs there
- [x] T003 [P] Create the test database: `php bin/console --env=test doctrine:database:create --if-not-exists`
- [x] T004 [P] Write `docs/adr/0005-share-the-publication-lifecycle-through-a-mapped-superclass.md` from `research.md` decision 1, including the trait, STI, JTI and embeddable alternatives and why each was rejected
- [x] T005 [P] Write `docs/adr/0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md` from `research.md` decision 5, including the honest limitation on FR-012 regeneration

**Unplanned work found while doing T003**: the test environment could not connect
at all. Symfony deliberately does not load `.env.local` when `APP_ENV=test`, so
the development credentials were invisible and the suite fell back to the
placeholder in `.env`. Fixed by adding `.env.test.local` (gitignored, as local
credentials should be) with a comment explaining why the file has to exist. CI is
unaffected — the workflow sets `DATABASE_URL` against its service container. This
was latent from the moment the project was created and would have blocked the
first test regardless of this feature.

**Checkpoint**: reached. `vendor/bin/phpunit --testsuite unit` runs and reports
"No tests executed!", which is the correct answer before any test exists.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The pieces every story needs — the exception vocabulary, the status
enum, the pure slug generator, and the account that every article and file points
at. Nothing here is story-specific.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T006 Create the domain exception hierarchy in `src/Exception/`: `DomainException` plus `InvalidStatusTransition`, `ContentNotPublishable`, `SlugIsFrozen`, `MediaMissingAltText`, `HierarchyWouldBeCircular`, `PageStillHasChildren`, `UserStillOwnsContent`, `UnsupportedMediaType` — each carrying its context as typed accessors, never only a message string (see `contracts/domain-api.md`)
- [x] T007 [P] Create `src/Entity/ContentStatus.php` — backed enum `draft|published|archived` with `allowedTransitions()`, `canTransitionTo()` and `label()` per `data-model.md`
- [x] T008 [P] Create `src/Repository/SluggedRepository.php` — one-method interface `existsWithSlug(string $slug): bool`
- [x] T009 [P] Create `src/Service/Slug/SlugGenerator.php` — pure, `AsciiSlugger`-based, lowercased and hyphen-trimmed, falling back to a short random token when a title yields nothing usable. No database, no container state
- [x] T010 Create `src/Entity/User.php` — table `app_user`, implementing `UserInterface` and `PasswordAuthenticatedUserInterface`, `roles` as `list<string>` with `ROLE_USER` always appended on read, `?int $id = null`, validation attributes per `data-model.md`
- [x] T011 Create `src/Repository/UserRepository.php` with `findOneByEmail(): ?User`
- [x] T012 Create `src/Factory/UserFactory.php` (Foundry 2 class-based) with `admin()`, `editor()` and `author()` states
- [x] T013 Generate the first migration with `php bin/console doctrine:migrations:diff` — creates `app_user` with the unique index on `email`. Do not hand-edit the result
- [x] T014 Apply the migration to the dev and test databases and confirm `php bin/console doctrine:schema:validate` reports both mapping and schema in sync
- [x] T015 [P] Write `tests/Unit/Entity/ContentStatusTest.php` — assert every allowed transition in `data-model.md` is allowed and that every other pair is refused
- [x] T016 [P] Write `tests/Unit/Service/Slug/SlugGeneratorTest.php` — `Hello, World!` → `hello-world`; accented and non-Latin titles produce only `[a-z0-9-]`; a title of pure punctuation produces a non-empty slug; output always matches `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` (FR-009, SC-004)
- [x] T017 [P] Write `tests/Unit/Entity/UserTest.php` — `getRoles()` always contains `ROLE_USER` and deduplicates; `getUserIdentifier()` returns the email
- [x] T018 Write `tests/Integration/Repository/UserRepositoryTest.php` — `findOneByEmail()` hit and miss, and a duplicate email is refused by the unique constraint (FR-025)

**Three corrections to this list, made while doing the work rather than after it:**

- T006 planned `DomainException` as a subclass of PHP's `\DomainException`. It
  extends `\RuntimeException` instead. Every refusal here depends on runtime
  state — what an account owns, what status content is in — not on a programming
  mistake, and `\RuntimeException` is the accurate signal for that.
- T017 planned an assertion that `eraseCredentials()` leaves the hash intact.
  Symfony 8 removed that method from `UserInterface`, so there is nothing to
  assert; the assertion was dropped rather than a dead method written to satisfy
  it.
- The `User` constructor takes `$createdAt`, not `$now`, and `displayName` and
  `createdAt` are promoted. This came from Rector, which the quality gate runs in
  dry-run mode; the promotion was accepted and reformatted by hand rather than
  the rule being excluded.

**Checkpoint**: reached. `composer qa` passes in full — style clean, Rector clean,
PHPStan level max reports no errors, 66 tests and 90 assertions green. Accounts
exist and are proven; user story work can begin.

---

## Phase 3: User Story 1 — Publishing lifecycle (Priority: P1) 🎯 MVP

**Goal**: Content moves through draft → published → archived by named acts that
refuse every forbidden case, and a publication date that is stamped once and never
moves again.

**Independent Test**: create content, walk every allowed transition and attempt
every forbidden one, and assert the state and publication date after each step —
no container, no HTTP, and for the unit tests no database.

### Tests for User Story 1

- [ ] T019 [P] [US1] Write `tests/Unit/Entity/PublishableContentTransitionsTest.php` — each of the five permitted transitions succeeds; every other transition throws `InvalidStatusTransition` carrying the current and attempted status; `restore()` lands on `draft`, never `published` (FR-004)
- [ ] T020 [P] [US1] Write `tests/Unit/Entity/PublicationDateTest.php` — `publishedAt` is null on a new draft; set to the passed-in time on first publish; **unchanged** after unpublish → publish with a different time; unchanged by `archive()` (FR-005, FR-006, SC-005)
- [ ] T021 [P] [US1] Write `tests/Unit/Entity/ContentNotPublishableTest.php` — publishing with a blank title, a whitespace-only title, or an empty body throws `ContentNotPublishable` and leaves the status at `draft` (FR-007)

### Implementation for User Story 1

- [ ] T022 [US1] Create `src/Entity/PublishableContent.php` — abstract `#[ORM\MappedSuperclass]` with `id`, `title`, `slug`, `excerpt`, `content`, `status`, `publishedAt`, `createdAt`, `updatedAt`, the four transition methods, `isPublished()`, and a `#[ORM\PreUpdate]` callback maintaining `updatedAt`. No `setStatus()`, no `setPublishedAt()` (see `contracts/domain-api.md`)
- [ ] T023 [P] [US1] Create `src/Entity/Article.php` extending it — constructor takes `User $author` and `\DateTimeImmutable $now`; `author_id` is `NOT NULL` with `ON DELETE RESTRICT`. Category, tags and lead image arrive in later phases
- [ ] T024 [P] [US1] Create `src/Entity/Page.php` extending it — constructor takes `\DateTimeImmutable $now` only; no author, no category, no tags (FR-019). Parent and menu order arrive in Phase 6
- [ ] T025 [US1] Create `src/Repository/ArticleRepository.php` with `findOneBySlug()`, `findOnePublishedBySlug()`, `findPublished()` and `countPublished()`, all routed through one private published scope, ordered `published_at DESC, id DESC`. Return `list<Article>`, never a `QueryBuilder`
- [ ] T026 [US1] Create `src/Repository/PageRepository.php` with `findOneBySlug()` and `findOnePublishedBySlug()`, sharing the same published-scope approach
- [ ] T027 [P] [US1] Create `src/Factory/ArticleFactory.php` with `draft()`, `published()` and `archived()` states
- [ ] T028 [P] [US1] Create `src/Factory/PageFactory.php` with the same three states
- [ ] T029 [US1] Generate the migration creating `article` and `page` — unique index on each `slug`, index on `(status, published_at DESC)`, index on `author_id`, `article.author_id` → `app_user(id)` `ON DELETE RESTRICT`
- [ ] T030 [US1] Write `tests/Integration/Repository/ArticleRepositoryTest.php` — `findPublished()` returns published articles newest first and **excludes drafts and archived content**; `findOnePublishedBySlug()` misses on a draft while `findOneBySlug()` hits (FR-031, SC-003)
- [ ] T031 [US1] Write `tests/Integration/Repository/PageRepositoryTest.php` — same published-scope assertions for pages, proving US4 scenario 5 at the repository level

**Checkpoint**: the lifecycle is complete and proven. This is the MVP — every later phase adds fields and rules on top without changing it.

---

## Phase 4: User Story 2 — Stable, readable addresses (Priority: P1)

**Goal**: every piece of content carries a URL-safe address that is unique within
its kind and stops changing once readers can link to it.

**Independent Test**: create content with awkward titles and duplicate titles,
across two kinds, and assert the resulting addresses and the refusal to change a
published one.

### Tests for User Story 2

- [ ] T032 [P] [US2] Write `tests/Unit/Entity/SlugFreezeTest.php` — `assignSlug()` succeeds on a draft, and throws `SlugIsFrozen` once `publishedAt` is set, including after the content has been unpublished again (FR-012)
- [ ] T033 [P] [US2] Write `tests/Integration/Service/Slug/UniqueSlugGeneratorTest.php` — a second article with the same title gets `hello-world-2`, a third `hello-world-3`; a **page** titled the same as an article may also be `hello-world`, because uniqueness is per kind (FR-010, FR-011, US2 scenarios 3 and 4)

### Implementation for User Story 2

- [ ] T034 [US2] Create `src/Service/Slug/UniqueSlugGenerator.php` — takes `SlugGenerator` in the constructor and a `SluggedRepository` per call, appending `-2`, `-3`, … until the slug is free
- [ ] T035 [US2] Implement `SluggedRepository::existsWithSlug()` on `ArticleRepository` and `PageRepository`, declaring the interface on both
- [ ] T036 [US2] Add `assignSlug()` with the `SlugIsFrozen` guard to `src/Entity/PublishableContent.php`, and confirm no public `setSlug()` exists anywhere
- [ ] T037 [US2] Add the slug validation attributes to `Article` and `Page` — `UniqueEntity('slug')` and the `Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')` that is FR-009 in machine-readable form
- [ ] T038 [US2] Record in `docs/domain-model.md` that the slug **freeze** is enforced by the entity while **regeneration** on a title change is a service behaviour, so a caller bypassing the service leaves the slug stale — the gap identified in `research.md` decision 5, which closes when the admin layer gives editing a single entry point

**Checkpoint**: addresses behave as specified for both articles and pages

---

## Phase 5: User Story 3 — Sections and topics (Priority: P2)

**Goal**: articles sit in at most one section and carry any number of labels, and
removing either never destroys what it grouped.

**Independent Test**: build a small taxonomy, attach it to articles, delete parts
of it, and assert what survives.

### Tests for User Story 3

- [ ] T039 [P] [US3] Write `tests/Unit/Entity/CategoryHierarchyTest.php` — a category cannot be its own parent, nor its own grandparent, and the attempt throws `HierarchyWouldBeCircular` (FR-015)
- [ ] T040 [P] [US3] Write `tests/Integration/Service/Taxonomy/CategoryDeleterTest.php` — deleting a section leaves its articles with a null category and re-parents its child sections to the grandparent (FR-016, US3 scenarios 3 and 4)
- [ ] T041 [P] [US3] Write `tests/Integration/Repository/TaxonomyRepositoryTest.php` — `CategoryRepository::findChildrenOf()`, `TagRepository::findInUse()` returning only labels carried by at least one **published** article, and duplicate names producing distinct slugs (US3 scenario 6)

### Implementation for User Story 3

- [ ] T042 [P] [US3] Create `src/Entity/Category.php` — name, slug, description, self-referencing `parent` with the circularity walk in `setParent()`
- [ ] T043 [P] [US3] Create `src/Entity/Tag.php` — name and slug only, with no parent column, so nesting is unrepresentable rather than merely discouraged (FR-014)
- [ ] T044 [US3] Add the `category` association (`ON DELETE SET NULL`) and the `tags` many-to-many via `article_tag` (both sides `ON DELETE CASCADE`) to `src/Entity/Article.php`, with `setCategory()`, `addTag()` and `removeTag()` guarding against duplicates, and `getTags()` returning `list<Tag>` rather than a Doctrine `Collection`
- [ ] T045 [P] [US3] Create `src/Repository/CategoryRepository.php` — `findOneBySlug()`, `findChildrenOf()`, `existsWithSlug()`
- [ ] T046 [P] [US3] Create `src/Repository/TagRepository.php` — `findOneBySlug()`, `findInUse()`, `existsWithSlug()`
- [ ] T047 [P] [US3] Create `src/Factory/CategoryFactory.php` (with a `childOf()` state) and `src/Factory/TagFactory.php`
- [ ] T048 [US3] Create `src/Service/Taxonomy/CategoryDeleter.php` — clears the in-memory article associations, re-parents children, then removes the row
- [ ] T049 [US3] Generate the migration creating `category`, `tag` and `article_tag`, and adding `article.category_id` with its index and foreign key
- [ ] T050 [US3] Add `findPublishedByCategory()` and `findPublishedByTag()` to `ArticleRepository` and extend `tests/Integration/Repository/ArticleRepositoryTest.php` to prove both apply the published scope (FR-030, FR-031)

**Checkpoint**: taxonomy works and deleting any part of it destroys nothing

---

## Phase 6: User Story 4 — Standalone pages (Priority: P2)

**Goal**: pages nest, carry an explicit position, and refuse to be removed while
something still hangs off them.

**Independent Test**: build a small page tree with positions, list it, and attempt
the forbidden deletions and the circular parent.

### Tests for User Story 4

- [ ] T051 [P] [US4] Write `tests/Unit/Entity/PageHierarchyTest.php` — a page cannot become its own ancestor at any depth (`HierarchyWouldBeCircular`); `hasChildren()` reflects the association
- [ ] T052 [P] [US4] Write `tests/Integration/Service/Content/PageDeleterTest.php` — deleting a page with children throws `PageStillHasChildren` and removes nothing; deleting a leaf succeeds (FR-018)

### Implementation for User Story 4

- [ ] T053 [US4] Add `parent` (self-referencing, `ON DELETE RESTRICT`), `menuOrder`, `getChildren()` and `hasChildren()` to `src/Entity/Page.php`, with the circularity walk in `setParent()`
- [ ] T054 [US4] Add `findPublishedChildrenOf(?Page $parent)` — ordered by `menu_order` — and `countChildrenOf()` to `src/Repository/PageRepository.php`
- [ ] T055 [US4] Create `src/Service/Content/PageDeleter.php` throwing `PageStillHasChildren` when the page still has any
- [ ] T056 [US4] Add a `childOf()` state to `src/Factory/PageFactory.php`
- [ ] T057 [US4] Generate the migration adding `page.parent_id` (`ON DELETE RESTRICT`), `page.menu_order` defaulting to `0`, and the `(parent_id, menu_order)` index
- [ ] T058 [US4] Extend `tests/Integration/Repository/PageRepositoryTest.php` — sibling pages come back in `menu_order`, and only published ones (US4 scenario 3)

**Checkpoint**: the page tree and the menu ordering behave as specified

---

## Phase 7: User Story 5 — Catalogued files (Priority: P3)

**Goal**: uploads are recorded under generated names, described for people who
cannot see them, and removable without taking content with them.

**Independent Test**: catalogue files including a hostile original name, attach and
detach them, and delete one that is in use.

### Tests for User Story 5

- [ ] T059 [P] [US5] Write `tests/Unit/Service/Media/StoredFilenameGeneratorTest.php` — the generated name contains no path separator, never derives from a supplied name such as `../../evil.php`, and an unsupported MIME type throws `UnsupportedMediaType` (FR-021, FR-022, US5 scenario 1)
- [ ] T060 [P] [US5] Write `tests/Unit/Entity/FeaturedImageTest.php` — attaching a file with no alternative text throws `MediaMissingAltText` for both `Article` and `Page`; attaching `null` always succeeds (FR-023)
- [ ] T061 [P] [US5] Write `tests/Integration/Service/Media/MediaDeleterTest.php` — deleting a file used as a lead image leaves the article intact with no lead image, **both in the database and in the already-loaded entity** (FR-024, `research.md` decision 6)

### Implementation for User Story 5

- [ ] T062 [US5] Create `src/Entity/Media.php` — `filename` taken through the constructor with no setter at all, `originalName` as display text, `mimeType`, `size`, nullable `altText`, `uploadedBy` (`ON DELETE RESTRICT`), `uploadedAt`
- [ ] T063 [US5] Create `src/Service/Media/StoredFilenameGenerator.php` — `bin2hex(random_bytes(16))` plus an extension from a MIME allow-list, ignoring any supplied name entirely
- [ ] T064 [US5] Add `featuredImage` with the `MediaMissingAltText` guard to `src/Entity/Article.php` and `src/Entity/Page.php` (`ON DELETE SET NULL` on both)
- [ ] T065 [P] [US5] Create `src/Repository/MediaRepository.php` — `findRecent()` and `countUploadedBy()`
- [ ] T066 [P] [US5] Create `src/Factory/MediaFactory.php` with a `withoutAltText()` state
- [ ] T067 [US5] Create `src/Service/Media/MediaDeleter.php` — clears every referencing lead image in memory, then removes the row
- [ ] T068 [US5] Generate the migration creating `media` and adding `featured_image_id` to `article` and `page` with their indexes and `ON DELETE SET NULL` foreign keys

**Checkpoint**: files are catalogued safely and removable without collateral damage

---

## Phase 8: User Story 6 — Account deletion is refused while content is owned (Priority: P3)

**Goal**: an account that still authors articles or owns files cannot be removed,
in any content state.

**Independent Test**: attribute content to an account and attempt deletion, then
archive the content and attempt again.

### Tests for User Story 6

- [ ] T069 [P] [US6] Write `tests/Integration/Service/Account/UserDeleterTest.php` — deletion is refused for an author of articles and for an owner of files; **still refused when every one of their articles is archived**, because archiving is not a release of ownership; succeeds for an account owning nothing (FR-028, spec Edge Cases)

### Implementation for User Story 6

- [ ] T070 [US6] Add `countByAuthor(User $author)` to `ArticleRepository` and confirm `countUploadedBy()` on `MediaRepository` counts regardless of content status
- [ ] T071 [US6] Create `src/Service/Account/UserDeleter.php` throwing `UserStillOwnsContent` carrying the counts, so a future admin screen can say what is blocking rather than only that something is

**Checkpoint**: all six user stories are independently functional and proven

---

## Phase 9: Polish & Cross-Cutting Concerns

- [ ] T072 [P] Extend `src/DataFixtures/AppFixtures.php` and `src/Story/AppStory.php` to build a realistic development dataset entirely from the factories — an admin, an editor and two authors, a small section tree, labels, a dozen articles across all three states, a few nested pages, and some catalogued files
- [ ] T073 [P] Update `docs/domain-model.md` so the intended model and the delivered one agree, including the `app_user` table name and the `PublishableContent` superclass, which the document does not currently mention
- [ ] T074 [P] Re-check the migration count in `plan.md` and `research.md` against what was actually generated. Both were corrected from one to five when this task list was written; if the implementation produced a different number, correct them again rather than leaving the figure stale
- [ ] T075 [P] Update `docs/status.md` to record what now exists: entities, repositories, migrations, factories and the test suite — and state plainly that there are no functional tests in this feature because it adds no routes
- [ ] T076 Run `composer qa` and fix every finding **at its cause**. No lowered PHPStan level, no baseline, no suppression, no skipped test (constitution principle III)
- [ ] T077 Have the `symfony-reviewer` agent review the change against `CLAUDE.md`, and act on what it finds (constitution, Development Workflow phase 4)
- [ ] T078 Walk the validation scenarios in `quickstart.md` by hand, including the foreign-key `delete_rule` query, and confirm each matches `data-model.md`
- [ ] T079 Update `spec.md` and `plan.md` for anything the implementation settled differently, so the specification is never left describing something that was not built (constitution principle II)

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1 (Setup)** — no dependencies, start immediately
- **Phase 2 (Foundational)** — depends on Phase 1; **blocks every user story**
- **Phase 3 (US1)** — depends on Phase 2
- **Phase 4 (US2)** — depends on Phase 3, because slugs are assigned to the entities US1 creates
- **Phase 5 (US3)**, **Phase 6 (US4)**, **Phase 7 (US5)** — each depends on Phase 3 only, and they do not depend on one another
- **Phase 8 (US6)** — depends on Phase 3 (authored articles) and Phase 7 (owned files); the article half can be proven after Phase 3 alone
- **Phase 9 (Polish)** — depends on every phase being complete

### Story dependencies, stated honestly

These stories are **not** fully independent of one another, and pretending
otherwise would produce a task list that does not work. US1 builds the entities
the rest attach fields to; everything else hangs off it. What each later phase
does keep is an independent *proof*: US3 can be verified without touching pages,
US5 without touching taxonomy, US6 without touching slugs.

The one story that could in principle be reordered is US2 — slugs — which is P1 in
`spec.md` and is scheduled second because it edits the same entity files US1
creates, and doing both at once would mean one commit covering two concerns.

### Within each phase

- Test tasks are listed before the implementation they cover and are expected to
  fail until it lands
- Entities before repositories, repositories before services
- Migrations last within their phase, generated by `diff` once the mapping is
  settled, and never edited afterwards

### Parallel opportunities

- Phase 1: T002–T005 all touch different files
- Phase 2: T007, T008, T009 are independent; T015–T017 are independent test files
- Phase 3: the three test files T019–T021 in parallel; then T023 and T024 in
  parallel; then T027 and T028 in parallel
- Phases 5, 6 and 7 can run concurrently once Phase 4 is done, by different people
- Phase 9: T072–T075 are four separate documents

---

## Parallel Example: User Story 1

```bash
# The three unit test files first — different files, no shared state:
Task: "Write tests/Unit/Entity/PublishableContentTransitionsTest.php"
Task: "Write tests/Unit/Entity/PublicationDateTest.php"
Task: "Write tests/Unit/Entity/ContentNotPublishableTest.php"

# After PublishableContent lands, the two subclasses in parallel:
Task: "Create src/Entity/Article.php extending PublishableContent"
Task: "Create src/Entity/Page.php extending PublishableContent"

# Then the two factories in parallel:
Task: "Create src/Factory/ArticleFactory.php"
Task: "Create src/Factory/PageFactory.php"
```

---

## Implementation Strategy

### MVP first (Phases 1–3)

1. Phase 1 — Setup
2. Phase 2 — Foundational (blocks everything)
3. Phase 3 — US1, the publishing lifecycle
4. **Stop and validate**: run `composer qa`, then walk the US1 scenarios in
   `quickstart.md`
5. At this point the CMS has a proven content lifecycle and nothing else. That is
   a real milestone: every later feature, including the admin screens and the
   public site, asks `isPublished()` and gets one answer.

### Incremental delivery

Each phase from 4 onwards is a commit-sized increment that leaves the suite green:

- Phase 4 → addresses are stable and unique
- Phase 5 → content is organised
- Phase 6 → the page tree and menu exist
- Phase 7 → files are catalogued
- Phase 8 → accounts cannot be deleted out from under their content

### Commits

One concern per commit, Conventional Commits, referencing the spec — for example
`feat(article): add the publication lifecycle (specs/001-core-content-model)`.
A migration is committed together with the mapping change that produced it, never
separately, because the two are only correct as a pair.

---

## Notes

- `[P]` means a different file and no dependency on unfinished work
- Every test asserts on an exception **class** and its typed accessors, never on a
  message string — messages get reworded, rules do not
- Run `composer qa` before each commit, not only at the end of the feature; a
  finding is cheapest at the moment it is introduced
- Where a task and the design documents disagree once the code is written, the
  documents are corrected in the same commit — T074, T075 and T079 exist because
  that has already happened once, with the migration count