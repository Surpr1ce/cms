<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Slug;
use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ArticleController extends AbstractController
{
    public function __construct(private readonly ArticleRepository $articles)
    {
    }

    /**
     * The slug requirement is the same expression {@see Slug::PATTERN} enforces
     * when content is created, so an address that could never have been
     * generated is refused by the router and never reaches this method.
     */
    #[Route(
        '/articles/{slug}',
        name: 'article_show',
        requirements: ['slug' => Slug::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function show(string $slug): Response
    {
        $article = $this->articles->findOnePublishedBySlugWithRelations($slug);

        if (!$article instanceof Article) {
            // The same exception, with the same empty message, for a draft, for
            // something archived and for an address that never existed. FR-002
            // asks that a reader cannot tell them apart; the way to guarantee
            // that is to have nothing here that knows which case it was.
            throw $this->createNotFoundException();
        }

        return $this->render('public/article/show.html.twig', [
            'article' => $article,
        ]);
    }
}
