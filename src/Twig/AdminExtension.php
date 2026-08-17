<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Page;
use DateTimeImmutable;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the administration navigation a subject to ask a voter about.
 *
 * `is_granted('PAGE_EDIT')` with no subject would not reach PageVoter at all —
 * its `supports()` requires a Page, and a voter that abstains on an unrecognised
 * subject is the safe design. So the template needs something to hand it.
 *
 * The alternative was `is_granted('ROLE_EDITOR')` in the template, which would
 * work today and would be a second opinion about who may edit a page. When the
 * rule changes in PageVoter, that template would go on saying what it always
 * said, and the menu would offer a link that leads to a refusal — or worse, hide
 * one that does not.
 */
final class AdminExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('page_probe', $this->pageProbe(...)),
        ];
    }

    /**
     * An unsaved page, purely as something for the voter to look at. PageVoter
     * decides by role alone, so any page gives the same answer.
     */
    public function pageProbe(): Page
    {
        return new Page('probe', 'probe', new DateTimeImmutable());
    }
}
