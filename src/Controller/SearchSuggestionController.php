<?php

declare(strict_types=1);

namespace App\Controller;

use App\Search\SearchHit;
use App\Search\SearchQuery;
use App\Search\SiteSearch;

use function array_map;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What to offer somebody who is still typing.
 *
 * The same search, asked for six rows instead of twenty, answered as JSON. It is
 * deliberately *not* part of the API in `src/ApiResource/`: that is a documented,
 * versioned, read-only interface onto content, and this is a detail of how one
 * text box behaves. Putting it there would promise a stability this does not have.
 *
 * Three things this route must keep, all of which it gets by going through
 * `SiteSearch` rather than around it:
 *
 * **It shows only published work.** A word that appears solely in a draft returns
 * an empty list — the same empty list as a word that appears nowhere, which is
 * what stops this becoming a way to discover unfinished work by guessing.
 *
 * **It is bounded.** The reader's words are trimmed and truncated by
 * `SearchQuery` before the database sees them, and a query too short to be worth
 * running answers an empty list without asking anything.
 *
 * **It is limited.** This is the one route on the site designed to be asked
 * repeatedly, which makes it the cheapest to abuse; every request is a full-text
 * match over two tables. A client past its allowance is told so with a 429 rather
 * than an empty list, because a suggestion box that silently goes quiet looks
 * broken.
 */
final class SearchSuggestionController extends AbstractController
{
    /**
     * Enough to be useful, few enough to read without scrolling. A longer list
     * is what the search page is for.
     */
    private const int SUGGESTIONS = 6;

    public function __construct(
        private readonly SiteSearch $search,
        private readonly RateLimiterFactoryInterface $searchSuggestionsLimiter,
    ) {
    }

    #[Route('/search/suggestions', name: 'search_suggestions', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->searchSuggestionsLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(
                ['suggestions' => []],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $query = SearchQuery::from($request->query->getString('q'));

        return new JsonResponse([
            'suggestions' => array_map(
                $this->toSuggestion(...),
                $this->search->suggest($query, self::SUGGESTIONS),
            ),
        ]);
    }

    /**
     * A suggestion is a label and somewhere to go.
     *
     * The address is generated here rather than assembled in the browser, so
     * that the one place route shapes are decided stays the router — the same
     * reason nothing in `templates/` writes an address by hand.
     *
     * No summary, no date, no rank. A dropdown offering four lines per entry is
     * a search results page in a smaller box, and the fields are omitted rather
     * than sent and ignored: a field that is not written cannot leak.
     *
     * @return array{title: string, kind: string, url: string}
     */
    private function toSuggestion(SearchHit $hit): array
    {
        return [
            'title' => $hit->title,
            'kind' => $hit->kind,
            'url' => $this->generateUrl(
                $hit->isArticle() ? 'article_show' : 'page_show',
                ['slug' => $hit->slug],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ),
        ];
    }
}
