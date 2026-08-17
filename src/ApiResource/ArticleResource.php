<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Article;
use App\Entity\Media;
use App\Entity\Slug;
use App\Entity\Tag;
use App\State\ArticleProvider;

use const DATE_ATOM;

/**
 * What a consumer of the API receives for an article.
 *
 * A read model rather than the entity, which is the decision this whole feature
 * turns on. Mapping `Article` directly would work and would be less code, and it
 * would expose whatever the entity happens to carry — including, through the
 * author association, an email address, a role list and a password hash. Every
 * one would have to be excluded by hand, and the day somebody adds a field to an
 * entity is the day it appears in the API without anybody deciding.
 *
 * This class says exactly what is public. A field not written here is not
 * exposed, which is the default the specification asks for rather than the one
 * that has to be maintained.
 *
 * Only Get and GetCollection are declared. There is no Post, Put, Patch or
 * Delete anywhere in this feature — FR-010, and the reason ApiIsReadOnlyTest
 * checks every method against every address rather than trusting this comment.
 */
#[ApiResource(
    shortName: 'Article',
    description: 'A published article.',
    operations: [
        new GetCollection(
            uriTemplate: '/articles',
            paginationItemsPerPage: 20,
        ),
        new Get(
            uriTemplate: '/articles/{slug}',
            uriVariables: ['slug'],
            requirements: ['slug' => Slug::ROUTE_PATTERN],
        ),
    ],
    provider: ArticleProvider::class,
)]
final class ArticleResource
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        /** The address, and the identifier. Internal numbers are not published. */
        public string $slug,
        public string $title,
        public ?string $summary,
        /** Sanitised markup — the same bytes a reader of the website receives. */
        public string $body,
        public ?string $publishedAt,
        /** A display name, and nothing else about the person. */
        public string $author,
        public ?string $section,
        public array $tags,
        public ?string $imageUrl,
        public ?string $imageAlt,
    ) {
    }

    /**
     * @param callable(string): string $mediaUrl builds the address of a stored file
     */
    public static function from(Article $article, callable $mediaUrl): self
    {
        $image = $article->getFeaturedImage();

        return new self(
            slug: $article->getSlug(),
            title: $article->getTitle(),
            summary: $article->getExcerpt(),
            body: $article->getContent(),
            publishedAt: $article->getPublishedAt()?->format(DATE_ATOM),
            author: $article->getAuthor()->getDisplayName(),
            section: $article->getCategory()?->getSlug(),
            tags: array_map(static fn (Tag $tag): string => $tag->getSlug(), $article->getTags()),
            imageUrl: !$image instanceof Media ? null : $mediaUrl($image->getFilename()),
            imageAlt: $image?->getAltText(),
        );
    }
}
