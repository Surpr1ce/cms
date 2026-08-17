<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Makes the site menu available to every template as `public_menu()`.
 *
 * A Twig function rather than a variable every controller passes down, because a
 * menu that is missing from one page because a controller forgot it is a defect
 * a reader finds before a developer does — and the error pages have no
 * controller of ours at all.
 *
 * The runtime is separate so that the query only happens on templates that
 * actually call it.
 */
final class MenuExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_menu', [MenuRuntime::class, 'menu']),
            // Sections, for the same reason and by the same mechanism. Before
            // feature 017 a reader could only reach a section by noticing the
            // small link under an article's title — the site's own structure was
            // invisible from the site.
            new TwigFunction('public_sections', [MenuRuntime::class, 'sections']),
        ];
    }
}
