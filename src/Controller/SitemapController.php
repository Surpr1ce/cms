<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\TagRepository;
use App\Service\Sitemap\SitemapBudget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What a crawler is told exists.
 *
 * Nothing here writes a query. Every list comes from a repository method the
 * public controllers already use — the ones that structurally cannot return
 * unpublished content — because a sitemap assembled from `findAll()` and
 * filtered afterwards is how a draft ends up being announced to a search engine.
 *
 * The limit is one document, and one document holds fifty thousand addresses.
 * {@see SitemapBudget} is where that number lives and where the four lists spend
 * it; what is here is only the order they spend it in. Before feature 019 the
 * articles and pages were capped at ten thousand each and the sections and labels
 * were not capped at all, so the document as a whole had no ceiling.
 */
final class SitemapController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly PageRepository $pages,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function index(): Response
    {
        // One budget spent across the four lists rather than a limit on each,
        // because the protocol counts addresses in a document and does not care
        // which kind they are: four separate limits of fifty thousand would be
        // four times the limit.
        $budget = new SitemapBudget();

        // The home page, which the template writes whether or not anything else
        // is on the site.
        $budget->reserve(1);

        $response = $this->render('public/sitemap.xml.twig', [
            'articles' => $budget->take(fn (int $limit): array => $this->articles->findPublished($limit)),
            'pages' => $budget->take(fn (int $limit): array => $this->pages->findPublished($limit)),
            // Sections and labels have no publication state of their own. They
            // are listings, and a listing of nothing is a valid empty page
            // rather than a 404 — which is feature 002's decision, not a new
            // one, and is why they can be listed unconditionally.
            //
            // They are spent last because they are the least valuable thing to
            // announce: a listing has no content of its own that a reader came
            // for. If a site ever does reach the ceiling, these are the
            // addresses it should lose.
            'categories' => $budget->take(fn (int $limit): array => $this->categories->findAllOrdered($limit)),
            // Labels in use only. A label nobody has applied lists nothing, and
            // announcing an empty page to a crawler is how a site acquires a
            // reputation for thin content.
            'tags' => $budget->take(fn (int $limit): array => $this->tags->findInUse($limit)),
        ]);

        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    /**
     * Where the sitemap is, and where a crawler should not go.
     *
     * Generated rather than a static file, so the sitemap's address comes from
     * the router and cannot drift if the route moves.
     */
    #[Route('/robots.txt', name: 'robots', methods: ['GET'])]
    public function robots(): Response
    {
        $content = "User-agent: *\n"
            ."Disallow: /admin\n"
            ."Disallow: /login\n"
            ."\n"
            .'Sitemap: '.$this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL)."\n";

        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
