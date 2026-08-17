<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Sitemap\SitemapAddresses;
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
 * {@see SitemapBudget} holds that number and the arithmetic;
 * {@see SitemapAddresses} holds which list gives up its addresses first. Neither
 * is here, because both are decisions about content rather than about a response
 * — before feature 019 the articles and pages were capped at ten thousand each
 * and the sections and labels were not capped at all, so the document as a whole
 * had no ceiling, and the pass that noticed also noticed the spend order sitting
 * in this action where no test could reach it.
 */
final class SitemapController extends AbstractController
{
    public function __construct(private readonly SitemapAddresses $addresses)
    {
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('public/sitemap.xml.twig', $this->addresses->collect());

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
