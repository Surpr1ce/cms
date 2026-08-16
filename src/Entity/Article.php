<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Dated content that appears in listings and feeds.
 *
 * Everything about its lifecycle lives in PublishableContent. What is here is
 * what makes an article an article rather than a page: it is always attributed
 * to somebody.
 *
 * The section, the labels and the lead image arrive in later phases of
 * specs/001-core-content-model; each is added with its own migration so that
 * this phase stays independently verifiable.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Index(name: 'idx_article_listing', columns: ['status', 'published_at'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Another article already uses this address.')]
class Article extends PublishableContent
{
    /**
     * SET NULL, not CASCADE: deleting a section leaves its articles in place,
     * uncategorised (FR-016). Deleting a grouping must never destroy what it
     * grouped.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /**
     * CASCADE here deletes the *association row*, never an article — which is
     * exactly FR-017: removing a label leaves the articles that carried it.
     *
     * @var Collection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'article_tag')]
    #[ORM\JoinColumn(name: 'article_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', onDelete: 'CASCADE')]
    private Collection $tags;

    public function __construct(
        string $title,
        string $slug,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $author,
        DateTimeImmutable $createdAt,
    ) {
        parent::__construct($title, $slug, $createdAt);

        $this->tags = new ArrayCollection();
    }

    /**
     * There is no setter. An article is attributed to whoever wrote it, and
     * reassigning authorship silently is not something the specification asks
     * for — if it ever is, it will be an explicit act with its own name.
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    /**
     * Assigning replaces whatever was there: an article is in at most one
     * section (FR-013). There is no addCategory(), because there is no second
     * one to add.
     */
    public function setCategory(?Category $category): void
    {
        $this->category = $category;
    }

    /**
     * A plain list rather than a Doctrine Collection, so templates and the API
     * cannot mutate the association behind the entity's back.
     *
     * @return list<Tag>
     */
    public function getTags(): array
    {
        return array_values($this->tags->toArray());
    }

    public function addTag(Tag $tag): void
    {
        if ($this->tags->contains($tag)) {
            return;
        }

        $this->tags->add($tag);
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tags->contains($tag);
    }
}
