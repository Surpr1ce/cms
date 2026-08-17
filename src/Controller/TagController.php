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
        // findOneBySlug, not findOneInUseBySlug, and that is FR-015 rather than
        // an oversight: a label that exists renders as an empty listing rather
        // than a 404, exactly as an empty section does. Nothing is disclosed by
        // it — a label carried only by drafts and a label carried by nothing at
        // all produce the same page, so the listing cannot tell a reader that
        // somebody is drafting about the subject.
        //
        // The JSON resource is stricter, because its own description promises a
        // label there is one a published article carries. See TagProvider.
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
