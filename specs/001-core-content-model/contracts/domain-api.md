# Phase 1 Contract: Domain API

**Feature**: `001-core-content-model` | **Date**: 2026-08-16

This feature exposes no HTTP endpoint and no command. Its interface is the PHP
surface that the admin screens, the public site and the read-only API will all be
written against, so that surface is the contract — signatures only, no bodies.

Anything not listed here is private to the feature and may change without a
specification update. Anything listed here is a promise: changing it means
changing the callers, so it gets a specification update first.

## Entities — `App\Entity`

### `PublishableContent` (abstract)

```php
abstract class PublishableContent
{
    public function getId(): ?int;

    public function getTitle(): string;
    public function setTitle(string $title): void;

    public function getSlug(): string;
    /** @throws SlugIsFrozen once the content has been published */
    public function assignSlug(string $slug): void;

    public function getExcerpt(): ?string;
    public function setExcerpt(?string $excerpt): void;

    public function getContent(): string;
    public function setContent(string $content): void;

    public function getStatus(): ContentStatus;
    public function isPublished(): bool;

    public function getPublishedAt(): ?\DateTimeImmutable;
    public function getCreatedAt(): \DateTimeImmutable;
    public function getUpdatedAt(): \DateTimeImmutable;

    /** @throws InvalidStatusTransition|ContentNotPublishable */
    public function publish(\DateTimeImmutable $now): void;
    /** @throws InvalidStatusTransition */
    public function unpublish(): void;
    /** @throws InvalidStatusTransition */
    public function archive(): void;
    /** @throws InvalidStatusTransition */
    public function restore(): void;
}
```

There is deliberately **no** `setStatus()`, **no** `setPublishedAt()` and **no**
`setSlug()`. Their absence is the contract, not an omission.

### `ContentStatus`

```php
enum ContentStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    /** @return list<ContentStatus> */
    public function allowedTransitions(): array;
    public function canTransitionTo(self $target): bool;
    public function label(): string;   // human-readable, for later admin screens
}
```

### `Article extends PublishableContent`

```php
final class Article extends PublishableContent
{
    public function __construct(User $author, \DateTimeImmutable $now);

    public function getAuthor(): User;

    public function getCategory(): ?Category;
    public function setCategory(?Category $category): void;

    /** @return list<Tag> */
    public function getTags(): array;
    public function addTag(Tag $tag): void;
    public function removeTag(Tag $tag): void;

    public function getFeaturedImage(): ?Media;
    /** @throws MediaMissingAltText when $media has no alternative text */
    public function setFeaturedImage(?Media $media): void;
}
```

`getTags()` returns a plain `list<Tag>`, not a Doctrine `Collection`. Templates
and the API get an array they cannot mutate behind the entity's back, and
`CLAUDE.md`'s "never leak the persistence layer" rule holds at the entity boundary
as well as at the repository one.

### `Page extends PublishableContent`

```php
final class Page extends PublishableContent
{
    public function __construct(\DateTimeImmutable $now);

    public function getParent(): ?Page;
    /** @throws HierarchyWouldBeCircular */
    public function setParent(?Page $parent): void;

    /** @return list<Page> */
    public function getChildren(): array;
    public function hasChildren(): bool;

    public function getMenuOrder(): int;
    public function setMenuOrder(int $menuOrder): void;

    public function getFeaturedImage(): ?Media;
    /** @throws MediaMissingAltText */
    public function setFeaturedImage(?Media $media): void;
}
```

### `Category`, `Tag`, `Media`, `User`

```php
final class Category
{
    public function __construct(string $name);
    public function getId(): ?int;
    public function getName(): string;
    public function setName(string $name): void;
    public function getSlug(): string;
    public function assignSlug(string $slug): void;
    public function getDescription(): ?string;
    public function setDescription(?string $description): void;
    public function getParent(): ?self;
    /** @throws HierarchyWouldBeCircular */
    public function setParent(?self $parent): void;
    /** @return list<self> */
    public function getChildren(): array;
}

final class Tag
{
    public function __construct(string $name);
    public function getId(): ?int;
    public function getName(): string;
    public function setName(string $name): void;
    public function getSlug(): string;
    public function assignSlug(string $slug): void;
}

final class Media
{
    public function __construct(
        string $filename,        // generated — see StoredFilenameGenerator
        string $originalName,
        string $mimeType,
        int $size,
        User $uploadedBy,
        \DateTimeImmutable $uploadedAt,
    );

    public function getId(): ?int;
    public function getFilename(): string;      // no setter, by design
    public function getOriginalName(): string;
    public function getMimeType(): string;
    public function getSize(): int;
    public function getAltText(): ?string;
    public function setAltText(?string $altText): void;
    public function hasAltText(): bool;
    public function getUploadedBy(): User;
    public function getUploadedAt(): \DateTimeImmutable;
}

final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(string $email, string $displayName, \DateTimeImmutable $now);

    public function getId(): ?int;
    public function getEmail(): string;
    public function setEmail(string $email): void;
    public function getUserIdentifier(): string;          // the email
    public function getPassword(): string;                // hash
    public function setPassword(string $hashedPassword): void;
    public function getDisplayName(): string;
    public function setDisplayName(string $displayName): void;
    /** @return list<string> always including ROLE_USER */
    public function getRoles(): array;
    /** @param list<string> $roles */
    public function setRoles(array $roles): void;
    public function getCreatedAt(): \DateTimeImmutable;
    public function eraseCredentials(): void;             // no-op; nothing plain-text is held
}
```

## Repositories — `App\Repository`

Every method returns a typed value. No method returns a `QueryBuilder`, a `Query`
or a Doctrine `Collection`.

```php
interface SluggedRepository
{
    public function existsWithSlug(string $slug): bool;
}

final class ArticleRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function findOneBySlug(string $slug): ?Article;
    public function findOnePublishedBySlug(string $slug): ?Article;
    /** @return list<Article> newest first, published only */
    public function findPublished(int $limit = 20, int $offset = 0): array;
    /** @return list<Article> */
    public function findPublishedByCategory(Category $category, int $limit = 20, int $offset = 0): array;
    /** @return list<Article> */
    public function findPublishedByTag(Tag $tag, int $limit = 20, int $offset = 0): array;
    public function countPublished(): int;
    public function countByAuthor(User $author): int;
}

final class PageRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function findOneBySlug(string $slug): ?Page;
    public function findOnePublishedBySlug(string $slug): ?Page;
    /** @return list<Page> published children in menu order */
    public function findPublishedChildrenOf(?Page $parent): array;
    public function countChildrenOf(Page $parent): int;
}

final class CategoryRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function findOneBySlug(string $slug): ?Category;
    /** @return list<Category> */
    public function findChildrenOf(?Category $parent): array;
}

final class TagRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function findOneBySlug(string $slug): ?Tag;
    /** @return list<Tag> tags carried by at least one published article */
    public function findInUse(): array;
}

final class MediaRepository extends ServiceEntityRepository
{
    public function countUploadedBy(User $uploader): int;
    /** @return list<Media> newest first */
    public function findRecent(int $limit = 20): array;
}

final class UserRepository extends ServiceEntityRepository
{
    public function findOneByEmail(string $email): ?User;
}
```

Every method whose name contains `Published` applies the published scope. That is
the guarantee behind FR-031: a caller never has to know what "visible" means.

## Services — `App\Service`

```php
namespace App\Service\Slug;

final class SlugGenerator                    // pure: no database, no container state
{
    public function generate(string $title): string;   // never empty, always URL-safe
}

final class UniqueSlugGenerator
{
    public function __construct(SlugGenerator $generator);
    public function generate(string $title, SluggedRepository $repository): string;
}
```

```php
namespace App\Service\Media;

final class StoredFilenameGenerator
{
    /** @throws UnsupportedMediaType when $mimeType is not in the allow-list */
    public function generate(string $mimeType): string;   // ignores any supplied name
}

final class MediaDeleter
{
    /** Clears every lead-image reference, then removes the file record. */
    public function delete(Media $media): void;
}
```

```php
namespace App\Service\Taxonomy;

final class CategoryDeleter
{
    /** Uncategorises the articles, re-parents the children, then removes the category. */
    public function delete(Category $category): void;
}
```

```php
namespace App\Service\Content;

final class PageDeleter
{
    /** @throws PageStillHasChildren */
    public function delete(Page $page): void;
}
```

```php
namespace App\Service\Account;

final class UserDeleter
{
    /** @throws UserStillOwnsContent when the account authors articles or owns files */
    public function delete(User $user): void;
}
```

## Exceptions — `App\Exception`

All extend `App\Exception\DomainException`, which extends PHP's
`\DomainException`. A caller may catch the base class to mean "a domain rule
refused this" without enumerating every case.

| Exception | Thrown when |
| --- | --- |
| `InvalidStatusTransition` | A transition the state machine does not allow |
| `ContentNotPublishable` | Publishing content with a blank title or body |
| `SlugIsFrozen` | Assigning a slug to already-published content |
| `MediaMissingAltText` | Using a file with no alternative text as a lead image |
| `HierarchyWouldBeCircular` | A category or page made its own ancestor |
| `PageStillHasChildren` | Deleting a page that still has child pages |
| `UserStillOwnsContent` | Deleting an account that authors articles or owns files |
| `UnsupportedMediaType` | Generating a stored filename for a type not allowed |

Each carries the values a caller needs — the current and attempted status, the
offending slug, the count of owned items — as typed accessors, so that assertions
and future error messages never parse a string.

## Test factories — `App\Factory`

Foundry 2 class-based factories, one per entity: `UserFactory`, `ArticleFactory`,
`PageFactory`, `CategoryFactory`, `TagFactory`, `MediaFactory`. Each produces a
valid instance with no arguments, and each provides the states the tests actually
need:

```php
ArticleFactory::new()->published()->create();
ArticleFactory::new()->archived()->create();
PageFactory::new()->childOf($parent)->create();
MediaFactory::new()->withoutAltText()->create();
```

These are part of the contract too: later features build their fixtures from
them, and renaming a state breaks tests in features not yet written.