<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A placeholder, and honestly labelled as one.
 *
 * Feature 003 installs the lock before feature 004 builds the door. A gate with
 * nothing behind it cannot be tested against a real address, and a lock first
 * exercised on the day the door arrives is a lock nobody has tested — so this
 * exists to give the firewall something real to protect.
 *
 * Feature 004 replaces this with the actual administration area. What must
 * survive that replacement is the route name `admin_dashboard`, which
 * security.yaml names as where somebody lands after signing in.
 */
final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }
}
