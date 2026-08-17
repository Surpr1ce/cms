<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\ContentNotPublishable;
use App\Exception\InvalidStatusTransition;
use App\Exception\MediaMissingAltText;
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
     * Advanced by the database layer on every write, and never by anything here.
     *
     * It is not content. It appears in no listing, no feed and no API response,
     * and no screen offers to edit it — it exists to answer one question at the
     * moment somebody saves: *is this still the record you were editing?* Without
     * it the second of two people editing the same article destroys the first
     * one's work and is told "Saved."
     *
     * `updatedAt` cannot do this job. It is a timestamp, and two saves within the
     * same second — which is exactly the case this guards — carry the same one.
     */
    // The type is spelled out rather than inferred from the property. Doctrine
    // validates a version column's type before it looks at the property, and
    // refuses to map one it was not told about.
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * SET NULL: deleting a catalogued file leaves the content intact, without a
     * lead image (FR-024). Declared here rather than twice in the subclasses,
     * because an article and a page each have exactly one and the rule guarding
     * it is the same rule.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Media $featuredImage = null;

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
     * Readable so an edit form can carry it back, and writable by nothing.
     *
     * There is no setter, by the same reasoning that gives this class no
     * `setStatus()`: a version somebody can assign is a version that proves
     * nothing.
     */
    public function getVersion(): int
    {
        return $this->version;
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

    public function getFeaturedImage(): ?Media
    {
        return $this->featuredImage;
    }

    /**
     * A file with no alternative text cannot be put in front of a reader, because
     * content must stay readable by people who cannot see the image.
     *
     * The check is here, at the point of use, rather than on Media itself: making
     * it a requirement of cataloguing would block an upload screen on a field
     * that belongs to the editing screen. Detaching the image — passing null —
     * always succeeds.
     *
     * @throws MediaMissingAltText
     */
    public function setFeaturedImage(?Media $featuredImage): void
    {
        if ($featuredImage instanceof Media && !$featuredImage->hasAltText()) {
            throw MediaMissingAltText::forFile($featuredImage->getFilename());
        }

        $this->featuredImage = $featuredImage;
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
