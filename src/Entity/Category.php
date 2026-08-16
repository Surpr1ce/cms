<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\HierarchyWouldBeCircular;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An exclusive grouping, answering "what part of the site is this in".
 *
 * Nestable, because a section of a site usually has subsections. An article
 * belongs to at most one, which is what distinguishes a category from a tag.
 *
 * Deleting a category never deletes what it grouped — see CategoryDeleter.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[UniqueEntity(fields: ['slug'], message: 'Another section already uses this address.')]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: Slug::PATTERN)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * SET NULL rather than CASCADE: deleting a grouping must never destroy what
     * it grouped. CategoryDeleter does better than the constraint and re-parents
     * children to their grandparent; this is the fallback for anything that
     * bypasses it.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    public function __construct(
        #[ORM\Column(length: 100)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        private string $name,
        string $slug,
    ) {
        $this->slug = Slug::assertWellFormed($slug);
        $this->children = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Walking the chain is bounded by the depth of a site's navigation, which is
     * single digits anywhere real. Left unchecked, a cycle is an infinite loop
     * the first time a template renders breadcrumbs, a long way from the cause.
     *
     * @throws HierarchyWouldBeCircular
     */
    public function setParent(?self $parent): void
    {
        if ($parent instanceof self && $this->wouldBeCircular($parent)) {
            throw HierarchyWouldBeCircular::forCategory($this->name);
        }

        $this->parent?->children->removeElement($this);
        $this->parent = $parent;
        $parent?->children->add($this);
    }

    /**
     * @return list<self>
     */
    public function getChildren(): array
    {
        return array_values($this->children->toArray());
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    private function wouldBeCircular(self $candidate): bool
    {
        for ($ancestor = $candidate; $ancestor instanceof self; $ancestor = $ancestor->parent) {
            if ($ancestor === $this) {
                return true;
            }
        }

        return false;
    }
}
