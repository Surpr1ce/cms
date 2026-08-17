<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Slug;
use App\State\PageProvider;

/**
 * A published standalone page.
 *
 * No author and no publication date shown, because a page has neither in any
 * meaningful sense — the same reason it is a separate entity from an article.
 */
#[ApiResource(
    shortName: 'Page',
    description: 'A published standalone page.',
    operations: [
        new GetCollection(uriTemplate: '/pages', paginationEnabled: false),
        new Get(
            uriTemplate: '/pages/{slug}',
            uriVariables: ['slug'],
            requirements: ['slug' => Slug::ROUTE_PATTERN],
        ),
    ],
    provider: PageProvider::class,
)]
final class PageResource
{
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $summary,
        public string $body,
        /** The parent's address, so a consumer can rebuild the menu structure. */
        public ?string $parent,
        public int $menuOrder,
        public ?string $imageUrl,
        public ?string $imageAlt,
    ) {
    }

    /**
     * @param callable(string): string $mediaUrl
     */
    public static function from(Page $page, callable $mediaUrl): self
    {
        $image = $page->getFeaturedImage();
        $parent = $page->getParent();

        return new self(
            slug: $page->getSlug(),
            title: $page->getTitle(),
            summary: $page->getExcerpt(),
            body: $page->getContent(),
            // Only a published parent is named. An unpublished one would
            // disclose a title through the back door, which is the leak the
            // website's breadcrumb already avoids.
            parent: $parent instanceof Page && $parent->isPublished() ? $parent->getSlug() : null,
            menuOrder: $page->getMenuOrder(),
            imageUrl: !$image instanceof Media ? null : $mediaUrl($image->getFilename()),
            imageAlt: $image?->getAltText(),
        );
    }
}
