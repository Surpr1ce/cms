<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\Page;
use App\Exception\PageStillHasChildren;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a page, or explains why it cannot.
 *
 * A page with pages nested below it is refused rather than re-parented, which is
 * the opposite of how sections are treated. The reason is that page nesting is
 * also the site's menu structure: silently moving a visitor's navigation around
 * is worse than declining and letting a person decide where those pages belong.
 *
 * `ON DELETE RESTRICT` on page.parent_id enforces the same rule in the schema,
 * so a caller that never heard of this service still cannot break it. What this
 * adds is an error somebody can act on, rather than a constraint-violation
 * message naming a foreign key.
 */
final readonly class PageDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageRepository $pages,
    ) {
    }

    /**
     * @throws PageStillHasChildren
     */
    public function delete(Page $page): void
    {
        $childCount = $this->pages->countChildrenOf($page);

        if ($childCount > 0) {
            throw PageStillHasChildren::with($page->getTitle(), $childCount);
        }

        $this->entityManager->remove($page);
        $this->entityManager->flush();
    }
}
