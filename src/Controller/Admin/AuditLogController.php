<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AuditEntryRepository;
use App\Security\AdministrationVoter;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading the record.
 *
 * Administrators only, and behind `MANAGE_ACCOUNTS` rather than a role name —
 * the same capability that governs who may create and remove accounts. Reading
 * who did what is the same kind of authority as deciding who may do it, and an
 * editor who could read every administrator's actions would have been handed a
 * surveillance tool nobody granted them.
 *
 * There is no write action on this controller and there is no form. That is the
 * design: nothing in this application can change or remove an entry, and the
 * absence of a route is what makes that true rather than a promise.
 */
#[Route('/admin/log')]
final class AuditLogController extends AbstractController
{
    public function __construct(
        private readonly AuditEntryRepository $entries,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route('', name: 'admin_audit_log', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_ACCOUNTS);

        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $fetched = $this->entries->findPage(
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('admin/log/index.html.twig', [
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }
}
