<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use App\Entity\Slug;
use App\Repository\PageRepository;

use function in_array;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Standalone pages at the root of the site: /about-us rather than
 * /pages/about-us.
 *
 * This route matches almost anything, so three things stop it swallowing the
 * rest of the site, and all three are tested:
 *
 *  1. It is registered with a low priority, so the prefixed routes are tried
 *     first.
 *  2. The slug requirement admits a single path segment only, so /articles/x
 *     cannot match it at all.
 *  3. The prefixes the site already uses are reserved, so a page called
 *     "Articles" cannot shadow /articles.
 *
 * The cost is that a future prefix — /search, say — means no page may be called
 * "search". That is accepted, and {@see RESERVED} is the one place a future
 * feature has to update.
 */
final class PageController extends AbstractController
{
    /**
     * First path segments this route must not answer for.
     *
     * @var list<string>
     */
    public const array RESERVED = ['articles', 'sections', 'topics', 'api', 'admin'];

    public function __construct(private readonly PageRepository $pages)
    {
    }

    #[Route('/{slug}', name: 'page_show', requirements: ['slug' => Slug::ROUTE_PATTERN], methods: ['GET'], priority: -100)]
    public function show(string $slug): Response
    {
        if (in_array($slug, self::RESERVED, true)) {
            throw $this->createNotFoundException();
        }

        $page = $this->pages->findOnePublishedBySlugWithRelations($slug);

        if (!$page instanceof Page) {
            throw $this->createNotFoundException();
        }

        return $this->render('public/page/show.html.twig', [
            // Not `page`: the listing templates already use that name for a page
            // of results, and one word meaning two things in the same template
            // set is how the wrong one gets rendered.
            'page_content' => $page,
            'ancestors' => $this->publishedAncestorsOf($page),
            'children' => $this->pages->findPublishedChildrenOf($page),
        ]);
    }

    /**
     * The trail from the top down to this page's parent.
     *
     * Only published ancestors appear. A draft parent is skipped rather than
     * shown, because a breadcrumb naming an unpublished page would disclose its
     * title — the same leak the 404 rules exist to prevent.
     *
     * @return list<Page>
     */
    private function publishedAncestorsOf(Page $page): array
    {
        $trail = [];

        for ($ancestor = $page->getParent(); $ancestor instanceof Page; $ancestor = $ancestor->getParent()) {
            if ($ancestor->isPublished()) {
                array_unshift($trail, $ancestor);
            }
        }

        return $trail;
    }
}
