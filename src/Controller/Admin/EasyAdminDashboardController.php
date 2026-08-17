<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\AdministrationVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The generic-CRUD half of the administration area.
 *
 * `CLAUDE.md` splits the administration in two: EasyAdmin for generic CRUD,
 * hand-written controllers for content and media. Articles, pages and files were
 * written by hand because each carries rules a scaffolded screen would fight — a
 * publication workflow, an ownership question, an upload boundary. Sections,
 * labels and accounts are the other kind, and this is what the split reserved
 * them for.
 *
 * Mounted at /admin/manage rather than /admin, so it cannot collide with the
 * screens already living under /admin/articles, /admin/pages and /admin/media.
 *
 * The menu is filtered by the same voters the controllers check. An editor sees
 * sections and labels; only an administrator sees accounts.
 */
#[AdminDashboard(routePath: '/admin/manage', routeName: 'admin_manage')]
final class EasyAdminDashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        // The dashboard needs its own check. `access_control` over ^/admin only
        // asks for a content role, and the CRUD controllers guard their own
        // actions — so without this an author reached the landing page of an
        // area they may do nothing in. A test found it.
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        return $this->render('admin/manage/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('CMS')
            ->setFaviconPath('favicon.ico')
        ;
    }

    /**
     * @return iterable<MenuItemInterface>
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToUrl('Back to the CMS', 'fa fa-arrow-left', $this->generateUrl('admin_dashboard'));

        // linkTo() takes the CRUD controller, not the entity — EasyAdmin 5
        // dropped linkToCrud(). Naming the controller is arguably the better
        // shape anyway: it is the controller that carries the permissions, and
        // an entity with two screens would have been ambiguous.
        if ($this->isGranted(AdministrationVoter::MANAGE_TAXONOMY)) {
            yield MenuItem::section('Taxonomy');
            yield MenuItem::linkTo(CategoryCrudController::class, 'Sections', 'fa fa-folder');
            yield MenuItem::linkTo(TagCrudController::class, 'Labels', 'fa fa-tag');
        }

        // Only an administrator. An editor runs the site; deciding who may run
        // it is a different authority, which AdministrationVoter already draws
        // the line on.
        if ($this->isGranted(AdministrationVoter::MANAGE_ACCOUNTS)) {
            yield MenuItem::section('People');
            yield MenuItem::linkTo(UserCrudController::class, 'Accounts', 'fa fa-user');
        }
    }
}
