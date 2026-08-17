<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ContentStatus;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\AuditEntryRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Security\AdministrationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where somebody lands after signing in.
 *
 * It was a placeholder from feature 003 until feature 016, and it stayed one
 * long after there was something to put on it: four links with nothing behind
 * them, on a site that by then held articles in three states, pages, files, a
 * search index and a log. A landing page made only of signposts is a page people
 * click through without reading.
 *
 * So it answers the questions somebody actually arrives with — how much is
 * waiting, what did I leave unfinished, what happened while I was away — and
 * only shows a panel the viewer is allowed to act on. An editor sees what an
 * editor can do; an author sees their own drafts and nothing about accounts.
 */
final class DashboardController extends AbstractController
{
    /**
     * Enough to be useful on arrival, few enough that nobody scrolls past the
     * rest of the page to reach the links.
     */
    private const int RECENT = 5;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly PageRepository $pages,
        private readonly MediaRepository $media,
        private readonly AuditEntryRepository $log,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $viewer = $this->getUser();

        // The firewall guarantees this. The guard is here because "the firewall
        // guarantees it" is the assumption that stops being true when a route
        // moves.
        if (!$viewer instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('admin/dashboard.html.twig', [
            'drafts' => $this->articles->count(['status' => ContentStatus::Draft]),
            'published' => $this->articles->countPublished(),
            'archived' => $this->articles->count(['status' => ContentStatus::Archived]),
            'pages' => $this->pages->count([]),
            'files' => $this->media->count([]),
            // The viewer's own unfinished work, which is the question an author
            // arrives with and the one nothing on this page used to answer.
            'mine' => $this->articles->findBy(
                ['author' => $viewer, 'status' => ContentStatus::Draft],
                ['createdAt' => 'DESC'],
                self::RECENT,
            ),
            // Only for somebody who may read the log at all. Fetching it for
            // everybody and hiding it in the template would be a query nobody
            // needed and a decision made in the wrong place.
            'recent' => $this->isGranted(AdministrationVoter::MANAGE_ACCOUNTS)
                ? $this->log->findPage(self::RECENT, 0)
                : [],
        ]);
    }
}
