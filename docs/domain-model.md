# Domain model

> This document describes the intended model. It is written during the *Discuss*
> phase and updated as entities are implemented; see
> [`status.md`](status.md) for what currently exists in code.

## Entities

```
User ──────┬─< Article >──── Category
           │        │
           │        └──< ArticleTag >── Tag
           │
           └─< Page

Media ─── referenced by Article.featuredImage, Page.featuredImage
```

### User

An account that can log into the administration area.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `email` | string, unique | Login identifier |
| `password` | string | Hashed, never stored or logged in plain text |
| `displayName` | string | Shown as the article author |
| `roles` | string[] | `ROLE_ADMIN`, `ROLE_EDITOR`, `ROLE_AUTHOR` |
| `createdAt` | immutable datetime | |

Role meanings:

- **`ROLE_AUTHOR`** — creates and edits *their own* drafts; cannot publish.
- **`ROLE_EDITOR`** — edits and publishes any content; manages taxonomy and media.
- **`ROLE_ADMIN`** — everything, including user management.

The distinction between author and editor is the reason authorisation needs
voters rather than role checks: "may edit this article" depends on who owns it,
not only on which role the user holds.

### Article

A dated piece of content that appears in listings and feeds.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `title` | string | |
| `slug` | string, unique | Generated from the title, stable once published |
| `excerpt` | text, nullable | Used in listings and meta description |
| `content` | text | HTML produced by the editor |
| `status` | enum | `draft`, `published`, `archived` |
| `publishedAt` | datetime, nullable | Set when first published |
| `author` | User | |
| `category` | Category, nullable | At most one |
| `tags` | Tag[] | Many-to-many |
| `featuredImage` | Media, nullable | |
| `createdAt` / `updatedAt` | datetime | |

Status transitions are methods, not setter calls:

```
draft ──publish()──▶ published ──archive()──▶ archived
  ▲                      │                        │
  └────unpublish()───────┘◀──────restore()────────┘
```

`publishedAt` is set by `publish()` on the first transition and not overwritten
afterwards, so re-publishing an unpublished article does not silently change its
date in feeds and listings.

### Page

Standalone content outside the chronological stream — "About", "Contact",
"Privacy". Same content and status fields as `Article`, without author
attribution, category, or tags, and with an optional `parent` for nesting and a
`menuOrder` for arrangement.

Separating `Page` from `Article` rather than adding a type discriminator keeps
each set of fields meaningful: an article always has an author and a date, a page
never needs either.

### Category

Hierarchical grouping: `name`, unique `slug`, optional `description`, optional
`parent`. An article belongs to at most one category.

### Tag

Flat labelling: `name`, unique `slug`. An article may carry many.

Categories and tags are deliberately different: a category answers "what section
is this in" and is exclusive; a tag answers "what is this about" and is not.

### Media

An uploaded file.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | int | |
| `filename` | string | Generated, never the client-supplied name |
| `originalName` | string | Retained for display only |
| `mimeType` | string | Determined from file content, not the extension |
| `size` | int | Bytes |
| `altText` | string, nullable | Required before use in content, for accessibility |
| `uploadedBy` | User | |
| `uploadedAt` | immutable datetime | |

The stored filename is always generated. Trusting a client-supplied name is how
path traversal and executable-extension uploads happen.

## Addresses

A slug is derived from the title, made unique within its entity type, and stops
changing once the content has been published. Three pieces enforce that, and it
is worth knowing which does what:

| Piece | Enforces |
| --- | --- |
| `SlugGenerator` | URL-safety; a usable address for any title, including one that reduces to nothing |
| `UniqueSlugGenerator` | Freedom from collision within one entity type, by appending `-2`, `-3`, … |
| `PublishableContent::assignSlug()` | The freeze after publication, and the shape of the address |
| The unique index on each `slug` column | The race two concurrent generators cannot see |

**A known gap.** The entity can guarantee that a slug *stops changing* after
publication, because that decision needs no other row. It cannot guarantee that
a slug is *regenerated* when a draft's title changes, because uniqueness needs
the database — that is `UniqueSlugGenerator`'s job, and a caller that sets a
title without going through it leaves the slug stale.

This is accepted while the only callers are tests and fixtures. It closes when
the administration layer gives content editing a single entry point. It is
recorded here, and in
[ADR 6](adr/0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md),
rather than left for someone to discover from behaviour.

## Invariants

These hold regardless of which delivery mechanism is in use, and each one is
covered by a test:

1. A slug is unique within its entity type and URL-safe.
2. A published article has a non-null `publishedAt`.
3. Only published content is reachable through public routes or the API.
4. An article cannot be published without a title and content.
5. Deleting a category does not delete its articles — they become uncategorised.
6. Deleting a user is refused while they still own content.
