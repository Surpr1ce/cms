<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Slug;
use App\Entity\Tag;
use App\Repository\ArticleRepository;
use App\Repository\TagRepository;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TagController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ArticleRepository $articles,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route(
        '/topics/{slug}',
        name: 'tag_show',
        requirements: ['slug' => Slug::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function show(string $slug, Request $request): Response
    {
        $tag = $this->tags->findOneBySlug($slug);

        if (!$tag instanceof Tag) {
            throw $this->createNotFoundException();
        }

        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $fetched = $this->articles->findPublishedPageByTag(
            $tag,
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('public/tag/show.html.twig', [
            'tag' => $tag,
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }
}
