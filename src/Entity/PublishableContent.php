<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ContentNotPublishable;
use App\Exception\InvalidStatusTransition;
use App\Exception\SlugIsFrozen;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Everything an article and a page have in common: a title, an address, a body,
 * and a lifecycle.
 *
 * Declared once as a mapped superclass so the two cannot drift apart — the
 * specification requires a page to behave exactly as an article does, and
 * identity maintained by hand is identity that lapses. See
 * docs/adr/0005-share-the-publication-lifecycle-through-a-mapped-superclass.md
 * for the alternatives that were rejected.
 *
 * There is deliberately no setStatus(), no setPublishedAt() and no setSlug().
 * Their absence is the design, not an omission.
 */
#[ORM\MappedSuperclass]
abstract class PublishableContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: Slug::PATTERN)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    /**
     * Empty while a draft is still being written. Publishing an empty body is
     * refused by publish(), which is where that rule belongs — making the column
     * nullable would put the same rule in two places and let "empty" mean two
     * different things.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column(length: 16, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * The address is required here rather than derived later, so that content
     * cannot exist without one. Obtain it from UniqueSlugGenerator, which is
     * what makes it unique within its kind.
     */
    protected function __construct(
        #[ORM\Column(length: 200)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        private string $title,
        string $slug,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->slug = Slug::assertWellFormed($slug);
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Changing the address of published content would break every existing link
     * to it, so it is refused. The freeze survives unpublishing: what matters is
     * that readers were once able to link to it, not the current status.
     *
     * @throws SlugIsFrozen
     */
    public function assignSlug(string $slug): void
    {
        if ($this->publishedAt instanceof DateTimeImmutable) {
            throw SlugIsFrozen::between($this->slug, $slug);
        }

        $this->slug = Slug::assertWellFormed($slug);
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): void
    {
        $this->excerpt = $excerpt;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    /**
     * The single definition of "visible". Every caller asks this rather than
     * comparing statuses, which is what keeps the website and the JSON API from
     * disagreeing about what a reader may see.
     */
    public function isPublished(): bool
    {
        return ContentStatus::Published === $this->status;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The publication date is stamped on the first publication and never moves
     * afterwards, so re-publishing something that was taken down does not
     * silently reorder it in listings and feeds.
     *
     * @throws InvalidStatusTransition|ContentNotPublishable
     */
    public function publish(DateTimeImmutable $now): void
    {
        $this->guardTransitionTo(ContentStatus::Published);

        if ('' === trim($this->title)) {
            throw ContentNotPublishable::withoutTitle();
        }

        if ('' === trim($this->content)) {
            throw ContentNotPublishable::withoutContent();
        }

        $this->status = ContentStatus::Published;
        $this->publishedAt ??= $now;
    }

    /**
     * @throws InvalidStatusTransition
     */
    public function unpublish(): void
    {
        if (ContentStatus::Published !== $this->status) {
            throw InvalidStatusTransition::between($this->status, ContentStatus::Draft);
        }

        $this->status = ContentStatus::Draft;
    }

    /**
     * @throws InvalidStatusTransition
     */
    public function archive(): void
    {
        $this->guardTransitionTo(ContentStatus::Archived);

        $this->status = ContentStatus::Archived;
    }

    /**
     * Archived content comes back as a draft, never straight to published.
     * Bringing something back and making it visible again are two decisions, and
     * whoever makes the first has not necessarily made the second.
     *
     * @throws InvalidStatusTransition
     */
    public function restore(): void
    {
        if (ContentStatus::Archived !== $this->status) {
            throw InvalidStatusTransition::between($this->status, ContentStatus::Draft);
        }

        $this->status = ContentStatus::Draft;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * @throws InvalidStatusTransition
     */
    private function guardTransitionTo(ContentStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw InvalidStatusTransition::between($this->status, $target);
        }
    }
}
