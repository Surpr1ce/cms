<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Pagination\Paginator;
use App\Service\Seo\PlainText;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Makes {@see PlainText} available to templates as the `summarise` filter.
 *
 * A filter rather than something each controller passes down, for the reason the
 * menu is a function: the summary is wanted in the base layout, in the feed and
 * on every public template, and a value one controller forgets to pass is a
 * preview that silently shows nothing.
 */
final class SeoExtension extends AbstractExtension
{
    public function __construct(
        private readonly PlainText $plainText,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('summarise', $this->plainText->summarise(...)),
        ];
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('canonical_url', $this->canonicalUrl(...)),
        ];
    }

    /**
     * The one address this page should be indexed under.
     *
     * Built from the request rather than from configuration, so that moving the
     * site is not a thing anybody has to remember — FR-017.
     *
     * The query string is dropped, with one exception. Dropping it is the whole
     * purpose: an address decorated with tracking parameters is the same page,
     * and saying so is what stops a search engine treating each variation as its
     * own. But page two of a listing is genuinely a different page, and
     * declaring it canonical to page one would ask a search engine to forget
     * everything past the first twenty articles.
     */
    public function canonicalUrl(): string
    {
        $request = $this->requests->getMainRequest();

        if (!$request instanceof Request) {
            return '';
        }

        $url = $request->getSchemeAndHttpHost().$request->getPathInfo();

        // Through the paginator's own rule rather than reading the parameter
        // directly. It is the one place that decides what "?page=abc" means,
        // and a canonical address disagreeing with the page that was actually
        // rendered would be worse than none — `getInt()` throws on that input
        // rather than answering, which is how this was found.
        $page = Paginator::pageNumberFrom($request->query->get('page'));

        return $page > 1 ? $url.'?page='.$page : $url;
    }
}
