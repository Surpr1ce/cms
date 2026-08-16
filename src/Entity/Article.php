<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleRepository;
use DateTimeImmutable;
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
    public function __construct(
        string $title,
        string $slug,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private readonly User $author,
        DateTimeImmutable $createdAt,
    ) {
        parent::__construct($title, $slug, $createdAt);
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
}
