<?php

declare(strict_types=1);

namespace App\Controller;

use App\Search\SearchQuery;
use App\Search\SiteSearch;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
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
        // See SearchSuggestionController: the limiter is named by attribute
        // rather than by the argument's name, which Symfony 8.1 deprecates.
        #[Target('search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $query = SearchQuery::from($request->query->getString('q'));

        // The same allowance the suggestion endpoint spends from. This route is
        // the more expensive of the two — twenty-one rows against six — and an
        // audit found it with no ceiling at all while the cheaper one had one,
        // which made the limit a formality anybody could step around.
        //
        // Consumed after the query is parsed, so that a request too short to be
        // worth running costs nothing: `isWorthRunning()` stops it before the
        // database is touched, and refusing it here would spend an allowance on
        // work that never happened.
        if ($query->isWorthRunning()
            && !$this->searchLimiter->create($request->getClientIp())->consume()->isAccepted()
        ) {
            return $this->render('public/search.html.twig', [
                'query' => $query,
                'page' => $this->paginator->paginate([], 1),
                'tooMany' => true,
            ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }

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
