<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a subscriber is told is new.
 *
 * Atom rather than RSS: it requires absolute addresses and unambiguous dates
 * where RSS merely permits them, and every reader that understands one
 * understands the other. Choosing the stricter format means a document that
 * validates is a document that is right.
 *
 * Like the sitemap, this reads only through a published-only repository method.
 * A feed is served to nobody in particular — there is no session, no voter and
 * no viewer to filter for — so anything it can reach, it publishes.
 */
final class FeedController extends AbstractController
{
    /**
     * The same twenty the front page shows, so that "the site" and "the feed"
     * mean the same thing to somebody comparing them.
     */
    private const int ENTRIES = 20;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/feed.xml', name: 'feed', methods: ['GET'])]
    public function index(): Response
    {
        // findPublishedPage rather than findPublished: the same rows, with the
        // author and the section join-fetched. The template renders both for
        // every entry, so without it a feed of twenty articles cost forty-one
        // queries where the front page showing the same twenty costs one.
        $articles = $this->articles->findPublishedPage(self::ENTRIES, 0);
        $newest = $articles[0] ?? null;

        $response = $this->render('public/feed.xml.twig', [
            'articles' => $articles,
            // The feed's own last-changed date, taken from the newest entry
            // rather than from the clock — with the clock, a reader polling an
            // unchanged site would be told it had just been updated, every time.
            //
            // The clock is the fallback for a site with nothing published at
            // all, where Atom still requires a date and there is no honest one
            // to give.
            'updatedAt' => $newest?->getUpdatedAt() ?? $this->clock->now(),
        ]);

        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');

        return $response;
    }
}
