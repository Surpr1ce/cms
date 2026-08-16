# Phase 1 Data Model: Core Content Model

**Feature**: `001-core-content-model` | **Date**: 2026-08-16

This document is the bridge between the domain language of `spec.md` (*account*,
*section*, *label*, *file*) and the names the code and the schema use. It is the
input to `doctrine:migrations:diff`; the generated migration is the authority on
the schema once committed, and this document is updated if the two ever disagree.

## Naming

| Specification | Class | Table |
| --- | --- | --- |
| Account | `App\Entity\User` | `app_user` |
| Article | `App\Entity\Article` | `article` |
| Page | `App\Entity\Page` | `page` |
| Section | `App\Entity\Category` | `category` |
| Label | `App\Entity\Tag` | `tag` |
| File | `App\Entity\Media` | `media` |
| — | `App\Entity\PublishableContent` | *(abstract, no table)* |
| — | `App\Entity\ContentStatus` | *(enum, stored as a string column)* |

`app_user` rather than `user`: `user` is a reserved word in PostgreSQL, and a
table name that has to be quoted in every hand-written query is a papercut with no
upside. `#[ORM\Table(name: 'app_user')]` is set explicitly.

## Conventions applied to every entity

- Identifier: `private ?int $id = null`, `#[ORM\GeneratedValue]`, PostgreSQL
  `IDENTITY` (already configured in `config/packages/doctrine.yaml`).
  Nullable because PHPStan runs with `checkUninitializedProperties: true` — see
  `research.md`, decision 8.
- Timestamps: `datetime_immutable`, mapped to `TIMESTAMP(0) WITHOUT TIME ZONE`.
- Column names: `snake_case`, produced by the underscore naming strategy already
  configured.
- No public setters where an invariant applies. Where a plain setter is listed
  below without qualification, no invariant attaches to that field.

---

## `PublishableContent` (abstract mapped superclass)

Shared by `Article` and `Page`. Produces no table of its own; every column below
appears in *both* `article` and `page`.

| Property | Column | Type | Null | Notes |
| --- | --- | --- | --- | --- |
| `id` | `id` | `INT` identity | no | Primary key |
| `title` | `title` | `VARCHAR(200)` | no | |
| `slug` | `slug` | `VARCHAR(200)` | no | Unique **per table**, not across both |
| `excerpt` | `excerpt` | `TEXT` | yes | Listings and meta description |
| `content` | `content` | `TEXT` | no | Empty string permitted while a draft |
| `status` | `status` | `VARCHAR(16)` | no | `ContentStatus`, `enumType` mapping |
| `publishedAt` | `published_at` | `TIMESTAMP(0)` | yes | Non-null whenever status is `published` |
| `createdAt` | `created_at` | `TIMESTAMP(0)` | no | Set in the constructor |
| `updatedAt` | `updated_at` | `TIMESTAMP(0)` | no | Maintained by `#[ORM\PreUpdate]` |

`content` is `NOT NULL` and may hold an empty string. A draft with nothing written
in it is a legitimate state; publishing it is not, and that is FR-007's job, not
the column's. Making the column nullable would put the same rule in two places
and let "empty" mean two different things.

### Behaviour

| Method | Rule |
| --- | --- |
| `publish(\DateTimeImmutable $now)` | Refuses unless status is `draft`. Refuses when `title` or `content` trims to empty (`ContentNotPublishable`). Sets `publishedAt` **only if it is still null**. |
| `unpublish()` | Refuses unless status is `published`. Leaves `publishedAt` untouched. |
| `archive()` | Permitted from `draft` and from `published`. Leaves `publishedAt` untouched. |
| `restore()` | Refuses unless status is `archived`. Returns to `draft`, never straight to `published`. |
| `assignSlug(string $slug)` | Refuses once `publishedAt` is non-null (`SlugIsFrozen`). |
| `isPublished(): bool` | `status === Published`. The single definition every caller uses. |

Any refused transition throws `InvalidStatusTransition` carrying the current and
attempted states, so a test asserts on the rule rather than on a message.

---

## `ContentStatus` (backed enum)

```
Draft     = 'draft'
Published = 'published'
Archived  = 'archived'
```

Transitions permitted (FR-004). Everything not listed is refused:

| From | To | Method |
| --- | --- | --- |
| `draft` | `published` | `publish()` |
| `published` | `draft` | `unpublish()` |
| `published` | `archived` | `archive()` |
| `draft` | `archived` | `archive()` |
| `archived` | `draft` | `restore()` |

---

## `Article` — extends `PublishableContent`

Adds, on top of every inherited column:

| Property | Column | Type | Null | Foreign key |
| --- | --- | --- | --- | --- |
| `author` | `author_id` | `INT` | **no** | `app_user(id)` `ON DELETE RESTRICT` |
| `category` | `category_id` | `INT` | yes | `category(id)` `ON DELETE SET NULL` |
| `featuredImage` | `featured_image_id` | `INT` | yes | `media(id)` `ON DELETE SET NULL` |
| `tags` | *(join table)* | — | — | `article_tag` |

Indexes on `article`:

| Index | Columns | Why |
| --- | --- | --- |
| unique | `slug` | FR-010, and the backstop under a slug race |
| — | `status, published_at DESC` | Every published listing filters and orders on exactly this pair |
| — | `author_id` | Ownership check when deleting an account |
| — | `category_id` | Articles-by-section listing |
| — | `featured_image_id` | Reference check when deleting a file |

Behaviour beyond the inherited lifecycle:

- `setCategory(?Category $category)` — replaces whatever was there; an article is
  in at most one section (FR-013).
- `addTag(Tag $tag)` / `removeTag(Tag $tag)` — idempotent, guard against
  duplicates in the collection.
- `setFeaturedImage(?Media $media)` — throws `MediaMissingAltText` when the file
  has no alternative text (FR-023). Passing `null` always succeeds.

## `article_tag` (join table)

| Column | Type | Foreign key |
| --- | --- | --- |
| `article_id` | `INT` | `article(id)` `ON DELETE CASCADE` |
| `tag_id` | `INT` | `tag(id)` `ON DELETE CASCADE` |

Primary key is the pair. `CASCADE` here deletes the *association*, never an
article — which is precisely FR-017.

---

## `Page` — extends `PublishableContent`

| Property | Column | Type | Null | Foreign key |
| --- | --- | --- | --- | --- |
| `parent` | `parent_id` | `INT` | yes | `page(id)` `ON DELETE RESTRICT` |
| `menuOrder` | `menu_order` | `INT` | no | default `0` |
| `featuredImage` | `featured_image_id` | `INT` | yes | `media(id)` `ON DELETE SET NULL` |

No `author_id`, no `category_id`, no tags — FR-019, and the reason `Page` is a
separate entity rather than an article with a type flag.

Indexes: unique `slug`; `status, published_at DESC`; `parent_id, menu_order`
(the menu is read in exactly that shape).

Behaviour:

- `setParent(?Page $parent)` — walks the ancestor chain and throws
  `HierarchyWouldBeCircular` if it reaches itself (FR-015).
- `setFeaturedImage(?Media $media)` — same alt-text rule as `Article`.

`ON DELETE RESTRICT` on `parent_id` is what makes FR-018 true even for a caller
that bypasses `PageDeleter`.

---

## `Category`

| Property | Column | Type | Null | Foreign key |
| --- | --- | --- | --- | --- |
| `id` | `id` | `INT` identity | no | |
| `name` | `name` | `VARCHAR(100)` | no | |
| `slug` | `slug` | `VARCHAR(120)` | no | unique |
| `description` | `description` | `TEXT` | yes | |
| `parent` | `parent_id` | `INT` | yes | `category(id)` `ON DELETE SET NULL` |

`setParent()` performs the same circularity walk as `Page`.

A note on the mismatch between the service and the constraint, so it is not
discovered later: `CategoryDeleter` re-parents children to their **grandparent**
(FR-016). The `ON DELETE SET NULL` constraint, which only applies when something
bypasses the service, makes them **top-level** instead. Both leave a coherent
tree and neither destroys data; the service result is the specified one and the
constraint result is the safe fallback.

## `Tag`

| Property | Column | Type | Null |
| --- | --- | --- | --- |
| `id` | `id` | `INT` identity | no |
| `name` | `name` | `VARCHAR(50)` | no |
| `slug` | `slug` | `VARCHAR(60)` | no, unique |

Flat by construction — no parent column, so nesting is not merely discouraged but
unrepresentable (FR-014).

---

## `Media`

| Property | Column | Type | Null | Notes |
| --- | --- | --- | --- | --- |
| `id` | `id` | `INT` identity | no | |
| `filename` | `filename` | `VARCHAR(255)` | no, unique | Generated; no setter exists |
| `originalName` | `original_name` | `VARCHAR(255)` | no | Display text only |
| `mimeType` | `mime_type` | `VARCHAR(100)` | no | From content, never from the extension |
| `size` | `size` | `INT` | no | Bytes |
| `altText` | `alt_text` | `VARCHAR(255)` | yes | Required at the point of use, not here |
| `uploadedBy` | `uploaded_by_id` | `INT` | no | `app_user(id)` `ON DELETE RESTRICT` |
| `uploadedAt` | `uploaded_at` | `TIMESTAMP(0)` | no | |

`filename` is taken through the constructor and has no setter at all, so FR-021
cannot be violated by a later caller — the only way to obtain one is
`StoredFilenameGenerator`, which never reads the supplied name.

`size` is a 4-byte integer, capping a single file at roughly 2 GB. That is far
above any upload this CMS will accept, and the upload limit is a separate
feature's concern.

---

## `User`

| Property | Column | Type | Null | Notes |
| --- | --- | --- | --- | --- |
| `id` | `id` | `INT` identity | no | |
| `email` | `email` | `VARCHAR(180)` | no, unique | Login identifier (FR-025) |
| `password` | `password` | `VARCHAR(255)` | no | Hash only (FR-026) |
| `displayName` | `display_name` | `VARCHAR(100)` | no | Author byline |
| `roles` | `roles` | `JSON` | no | `list<string>`; `ROLE_USER` always included on read |
| `createdAt` | `created_at` | `TIMESTAMP(0)` | no | |

Implements `UserInterface` and `PasswordAuthenticatedUserInterface` — see the
judgement call recorded in `plan.md`, Constitution Check. There is no
`eraseCredentials()`: Symfony 8 removed it from `UserInterface`, and no
plain-text credential is ever held on the object, so there is nothing to erase.

`password` defaults to an empty string rather than being required by the
constructor, because Symfony's password hasher takes the user object in order to
choose a hasher — the account has to exist before its hash can be computed. An
empty hash matches nothing, so the intermediate state cannot authenticate.

`getRoles()` returns the stored roles with `ROLE_USER` appended and duplicates
removed, which is Symfony's expected contract. The three meaningful roles are
`ROLE_ADMIN`, `ROLE_EDITOR`, `ROLE_AUTHOR`; their meanings are in
`docs/domain-model.md` and are enforced in a later feature, not this one.

---

## Constraint summary

Every foreign key in one place, because this is the table a reviewer actually
wants:

| From | To | On delete | Enforces |
| --- | --- | --- | --- |
| `article.author_id` | `app_user.id` | `RESTRICT` | FR-028 |
| `article.category_id` | `category.id` | `SET NULL` | FR-016 |
| `article.featured_image_id` | `media.id` | `SET NULL` | FR-024 |
| `page.parent_id` | `page.id` | `RESTRICT` | FR-018 |
| `page.featured_image_id` | `media.id` | `SET NULL` | FR-024 |
| `category.parent_id` | `category.id` | `SET NULL` | fallback for FR-016 |
| `article_tag.article_id` | `article.id` | `CASCADE` | FR-017 |
| `article_tag.tag_id` | `tag.id` | `CASCADE` | FR-017 |
| `media.uploaded_by_id` | `app_user.id` | `RESTRICT` | FR-028 |

Unique constraints: `app_user.email`, `article.slug`, `page.slug`,
`category.slug`, `tag.slug`, `media.filename`.

## Validation constraints

Symfony Validator attributes, used in addition to — never instead of — the rules
enforced in methods and in the schema. Validation reports a problem to a user
interface; the method and the constraint are what make the state unreachable.

| Entity | Constraints |
| --- | --- |
| `User` | `NotBlank` + `Email` on `email`; `UniqueEntity('email')`; `NotBlank` + `Length(max: 100)` on `displayName` |
| `Article`, `Page` | `NotBlank` + `Length(max: 200)` on `title`; `UniqueEntity('slug')`; `Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')` on `slug` |
| `Category` | `NotBlank` + `Length(max: 100)` on `name`; `UniqueEntity('slug')` |
| `Tag` | `NotBlank` + `Length(max: 50)` on `name`; `UniqueEntity('slug')` |
| `Media` | `NotBlank` on `originalName` and `mimeType`; `Positive` on `size`; `Length(max: 255)` on `altText` |

The slug `Regex` is the machine-readable form of FR-009 and is asserted directly
in the unit tests for `SlugGenerator`.