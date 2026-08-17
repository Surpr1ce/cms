<?php

declare(strict_types=1);

namespace App\Service\Sitemap;

use App\Entity\Category;
use App\Entity\Tag;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\TagRepository;
use DateTimeImmutable;

/**
 * Everything a sitemap document announces, and the order it gives up first.
 *
 * {@see SitemapBudget} holds the arithmetic — one ceiling, spent across four
 * lists. What lives here is the *policy*: which list is asked first, and
 * therefore which addresses a site past the ceiling loses. Those are different
 * decisions, and the second one used to be four keys in a render array inside
 * `SitemapController`, where reordering two lines silently changed what a large
 * site drops and nothing went red. The architecture pass before the release
 * called that a rule in the wrong layer, and it was right.
 *
 * The order is: articles, pages, sections, labels. Content first, because a
 * reader came for content; listings last, because a section page has nothing of
 * its own on it — announcing one to a crawler ahead of an article is announcing
 * the index instead of the book.
 *
 * The ceiling is a constructor argument with a default rather than a constant
 * read in place, so a test can hand it four addresses and watch which lists come
 * back empty. That is the only way this policy is observable: below the ceiling
 * every list is complete, and above it nobody wants to create fifty thousand rows
 * to find out.
 */
final readonly class SitemapAddresses
{
    public function __construct(
        private ArticleRepository $articles,
        private PageRepository $pages,
        private CategoryRepository $categories,
        private TagRepository $tags,
        private int $maximumAddresses = SitemapBudget::MAXIMUM_ADDRESSES,
    ) {
    }

    /**
     * @return array{
     *     articles: list<array{slug: string, updatedAt: DateTimeImmutable}>,
     *     pages: list<array{slug: string, updatedAt: DateTimeImmutable}>,
     *     categories: list<Category>,
     *     tags: list<Tag>,
     * }
     */
    public function collect(): array
    {
        $budget = new SitemapBudget($this->maximumAddresses);

        // The home page, which the template writes whether or not anything else
        // is on the site.
        $budget->reserve(1);

        return [
            // Columns rather than entities: fifty thousand hydrated articles to
            // print a slug and a date is how a route nobody has to sign in for
            // becomes a way to exhaust memory.
            'articles' => $budget->take(fn (int $limit): array => $this->articles->findPublishedAddresses($limit)),
            'pages' => $budget->take(fn (int $limit): array => $this->pages->findPublishedAddresses($limit)),
            // Sections and labels have no publication state of their own. They are
            // listings, and a listing of nothing is a valid empty page rather than
            // a 404 — feature 002's decision, not a new one.
            'categories' => $budget->take(fn (int $limit): array => $this->categories->findAllOrdered($limit)),
            // Labels in use only. A label nobody has applied lists nothing, and
            // announcing an empty page to a crawler is how a site acquires a
            // reputation for thin content.
            'tags' => $budget->take(fn (int $limit): array => $this->tags->findInUse($limit)),
        ];
    }
}
