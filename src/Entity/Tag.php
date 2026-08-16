<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A non-exclusive topic marker, answering "what is this about".
 *
 * Flat by construction: there is no parent column, so nesting is not merely
 * discouraged but unrepresentable. That is the deliberate difference from a
 * category — a section is exclusive and structural, a label is neither.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[UniqueEntity(fields: ['slug'], message: 'Another label already uses this address.')]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: Slug::PATTERN)]
    private string $slug;

    public function __construct(
        #[ORM\Column(length: 50)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 50)]
        private string $name,
        string $slug,
    ) {
        $this->slug = Slug::assertWellFormed($slug);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function assignSlug(string $slug): void
    {
        $this->slug = Slug::assertWellFormed($slug);
    }
}
