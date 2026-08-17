<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\TagRepository;
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
 * The limit is one document. The format holds fifty thousand addresses, which is
 * far past anything this CMS will carry before somebody reconsiders how it is
 * generated at all; recorded in the specification as a limit rather than
 * engineered around.
 */
final class SitemapController extends AbstractController
{
    /**
     * A high enough ceiling to mean "all of them" for a site of this size, and
     * still a ceiling — an unbounded fetch is a way to be knocked over by
     * anybody who asks for this address often enough.
     */
    private const int LIMIT = 10_000;

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
        $response = $this->render('public/sitemap.xml.twig', [
            'articles' => $this->articles->findPublished(self::LIMIT),
            'pages' => $this->pages->findPublished(self::LIMIT),
            // Sections and labels have no publication state of their own. They
            // are listings, and a listing of nothing is a valid empty page
            // rather than a 404 — which is feature 002's decision, not a new
            // one, and is why they can be listed unconditionally.
            'categories' => $this->categories->findAllOrdered(),
            // Labels in use only. A label nobody has applied lists nothing, and
            // announcing an empty page to a crawler is how a site acquires a
            // reputation for thin content.
            'tags' => $this->tags->findInUse(),
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
