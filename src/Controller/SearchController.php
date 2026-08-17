<?php

declare(strict_types=1);

namespace App\Controller;

use App\Search\SearchQuery;
use App\Search\SiteSearch;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finding an article by a word in it.
 *
 * Thin, like every other public controller: it turns what arrived into a
 * `SearchQuery`, asks, and hands the result to a template. It performs no status
 * check, decides no ranking and escapes nothing — those belong to the query
 * object, the search and Twig respectively.
 *
 * The one thing it does decide is that an empty query and a query that matched
 * nothing are different, and get different pages. Reading "no results" when you
 * have not asked anything reads as broken software.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly SiteSearch $search,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $query = SearchQuery::from($request->query->getString('q'));
        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $found = $this->search->search(
            $query,
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('public/search.html.twig', [
            'query' => $query,
            'page' => $this->paginator->paginate($found, $number),
        ]);
    }
}
