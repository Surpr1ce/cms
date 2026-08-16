<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\HierarchyWouldBeCircular;
use App\Repository\PageRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Standalone content outside the chronological stream — "About", "Contact",
 * "Privacy".
 *
 * It carries no author, no section and no labels, which is the whole reason it
 * is a separate entity rather than an article with a type flag: an article
 * always has an author and a date, and a page never needs either.
 *
 * Its lifecycle is identical to an article's because both inherit it from
 * PublishableContent rather than each declaring their own.
 *
 * The parent page, the menu position and the lead image arrive in later phases
 * of specs/001-core-content-model.
 */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Index(name: 'idx_page_listing', columns: ['status', 'published_at'])]
#[ORM\Index(name: 'idx_page_menu', columns: ['parent_id', 'menu_order'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Another page already uses this address.')]
class Page extends PublishableContent
{
    /**
     * RESTRICT, not SET NULL: a page with children below it cannot be deleted at
     * all (FR-018). Pages are treated more strictly than sections because page
     * nesting is also the menu structure, and silently rearranging a visitor's
     * navigation is a worse outcome than refusing the deletion.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $parent = null;

    /**
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $children;

    /**
     * An explicit position, so a menu is arranged deliberately rather than by
     * whatever order the rows happen to come back in.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $menuOrder = 0;

    public function __construct(string $title, string $slug, DateTimeImmutable $createdAt)
    {
        parent::__construct($title, $slug, $createdAt);

        $this->children = new ArrayCollection();
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * @throws HierarchyWouldBeCircular
     */
    public function setParent(?self $parent): void
    {
        if ($parent instanceof self && $this->wouldBeCircular($parent)) {
            throw HierarchyWouldBeCircular::forPage($this->getTitle());
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

    public function getMenuOrder(): int
    {
        return $this->menuOrder;
    }

    public function setMenuOrder(int $menuOrder): void
    {
        $this->menuOrder = $menuOrder;
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
