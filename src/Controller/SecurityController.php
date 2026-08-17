<?php

declare(strict_types=1);

namespace App\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // FR-006: somebody already recognised has no use for the form.
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('public/security/login.html.twig', [
            // One message for every refusal. Symfony distinguishes
            // "user not found" from "bad credentials" internally, and
            // hide_user_not_found — on by default — collapses both into
            // "Invalid credentials." before it reaches here. FR-002 and SC-002
            // depend on that staying collapsed, which LoginTest asserts.
            'error' => $authenticationUtils->getLastAuthenticationError(),

            // Echoed back so a mistyped password does not cost the address too.
            // Never the password.
            'lastEmail' => $authenticationUtils->getLastUsername(),
        ]);
    }

    /**
     * Intercepted by the firewall before it is ever called.
     *
     * The empty body is not an oversight — Symfony's logout listener handles the
     * request and this method exists only to give the route somewhere to point.
     */
    #[Route('/logout', name: 'logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new LogicException('The firewall intercepts /logout; this is never reached.');
    }
}
