<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\AdministrationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The way in to sections, labels and accounts.
 *
 * Counts rather than a list of links with nothing behind them: "3 sections" is
 * information, "Sections" is a signpost, and a landing page made only of
 * signposts is a page somebody clicks through without reading.
 */
#[Route('/admin/manage')]
final class ManageController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $sections,
        private readonly TagRepository $labels,
        private readonly UserRepository $accounts,
    ) {
    }

    #[Route('', name: 'admin_manage', methods: ['GET'])]
    public function __invoke(): Response
    {
        // The capability, not a role. An editor manages taxonomy and reaches
        // this page; only an administrator sees the accounts panel on it, which
        // the template decides with the same voter.
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        return $this->render('admin/manage/index.html.twig', [
            'sectionCount' => $this->sections->count([]),
            'labelCount' => $this->labels->count([]),
            'accountCount' => $this->accounts->count([]),
        ]);
    }
}
