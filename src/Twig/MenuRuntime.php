<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Category;
use App\Entity\Page;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Builds the site menu from published pages.
 *
 * One query per request, whose result is grouped in memory rather than queried
 * level by level. Nothing here caches: caching is out of scope for this feature,
 * and a cache added quietly is worse than a query nobody minded.
 *
 * Unpublished pages are absent because the repository never returns them, not
 * because this filters them out. A filter can be forgotten; a query that cannot
 * produce the rows cannot be.
 */
final class MenuRuntime implements RuntimeExtensionInterface
{
    /**
     * @var list<array{page: Page, children: list<Page>}>|null
     */
    private ?array $menu = null;

    /**
     * @var list<Category>|null
     */
    private ?array $sections = null;

    public function __construct(
        private readonly PageRepository $pages,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * Top-level published pages in menu order, each with its published children.
     *
     * A page whose parent is a draft is treated as top-level rather than hidden:
     * it is published, so a reader is entitled to reach it, and dropping it
     * because of its parent's status would hide published content — the opposite
     * of the mistake this feature is most careful about.
     *
     * @return list<array{page: Page, children: list<Page>}>
     */
    public function menu(): array
    {
        if (null !== $this->menu) {
            return $this->menu;
        }

        $published = $this->pages->findPublishedForMenu();

        $reachable = [];
        foreach ($published as $page) {
            $id = $page->getId();
            if (null !== $id) {
                $reachable[$id] = true;
            }
        }

        $childrenByParent = [];
        $roots = [];

        foreach ($published as $page) {
            $parent = $page->getParent();
            $parentId = $parent?->getId();

            if (null === $parentId || !isset($reachable[$parentId])) {
                $roots[] = $page;

                continue;
            }

            $childrenByParent[$parentId][] = $page;
        }

        $menu = [];
        foreach ($roots as $root) {
            $id = $root->getId();

            $menu[] = [
                'page' => $root,
                'children' => null === $id ? [] : ($childrenByParent[$id] ?? []),
            ];
        }

        return $this->menu = $menu;
    }

    /**
     * Top-level sections, for the site navigation.
     *
     * Top-level only, deliberately: a nested section belongs on its parent's
     * page, and a header that lists every section on a site with forty of them
     * is a header nobody reads.
     *
     * Sections have no publication state of their own — they are listings, and
     * an empty one renders as an empty page rather than a 404, which feature 002
     * decided. So there is nothing to filter and nothing that can leak.
     *
     * @return list<Category>
     */
    public function sections(): array
    {
        return $this->sections ??= $this->categories->findChildrenOf(null);
    }
}
