<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The front page: published articles, newest first.
 *
 * Note what is absent — there is no status check here. The controller asks for
 * a published page and cannot receive anything else, because the repository
 * method it calls has no code path that returns a draft. A check that a
 * controller performs is a check a controller can forget.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $fetched = $this->articles->findPublishedPage(
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('public/home.html.twig', [
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }
}
