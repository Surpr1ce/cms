<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PageRepository;
use DateTimeImmutable;
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
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Another page already uses this address.')]
class Page extends PublishableContent
{
    public function __construct(string $title, string $slug, DateTimeImmutable $createdAt)
    {
        parent::__construct($title, $slug, $createdAt);
    }
}
