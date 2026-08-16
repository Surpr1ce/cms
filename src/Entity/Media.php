<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A catalogued upload.
 *
 * The stored filename is generated and has no setter at all, so the rule that a
 * client-supplied name never reaches the filesystem cannot be broken by a later
 * caller — the only way to obtain one is StoredFilenameGenerator, which never
 * reads the supplied name. Path traversal and executable extensions are then
 * unreachable rather than filtered, and a filter is only as good as its last
 * review.
 *
 * This feature catalogues files. Receiving one, validating it, writing it to
 * disk and serving it back belongs to a later feature.
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[UniqueEntity(fields: ['filename'], message: 'A file is already stored under that name.')]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Required before the file is used as a lead image, but not before it is
     * catalogued — the uploader has not necessarily reached that field yet.
     * The rule is enforced at the point of use, in setFeaturedImage().
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $altText = null;

    public function __construct(
        #[ORM\Column(length: 255, unique: true)]
        private readonly string $filename,
        #[ORM\Column(length: 255)]
        #[Assert\NotBlank]
        private readonly string $originalName,
        #[ORM\Column(length: 100)]
        #[Assert\NotBlank]
        private readonly string $mimeType,
        #[ORM\Column]
        #[Assert\Positive]
        private readonly int $size,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $uploadedBy,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $uploadedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * No setter, by design. See the class comment.
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Display text only. It is never used to address the file on disk.
     */
    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * Determined from the file's content, not from its extension — an extension
     * is a claim by whoever uploaded the file.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): void
    {
        $altText = null === $altText ? null : trim($altText);

        $this->altText = '' === $altText ? null : $altText;
    }

    /**
     * Whitespace is not a description. An image labelled " " is as unreadable as
     * one labelled nothing, so setAltText() normalises it away and this reports
     * the truth.
     */
    public function hasAltText(): bool
    {
        return null !== $this->altText;
    }

    public function getUploadedBy(): User
    {
        return $this->uploadedBy;
    }

    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
