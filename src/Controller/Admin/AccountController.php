<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\PasswordResetController;
use App\Entity\User;
use App\Service\Account\PasswordResetService;

use function mb_strlen;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Somebody's own account.
 *
 * Behind the firewall, and about the signed-in person only — there is no
 * identifier in the address, so there is nothing to change to somebody else's.
 * Whose account this is comes from the session and from nowhere a request can
 * influence.
 *
 * The current password is required to change it, even though the session already
 * proves recognition. A browser left open on a shared machine is not consent to
 * hand the account over, and the whole point of a password change is that
 * afterwards only one person knows the password.
 */
#[Route('/admin/account')]
final class AccountController extends AbstractController
{
    public function __construct(private readonly PasswordResetService $resets)
    {
    }

    #[Route('', name: 'admin_account', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $account = $this->getUser();

        // The firewall guarantees this, and the guard is here because "the
        // firewall guarantees it" is exactly the assumption that stops being
        // true when a route moves.
        if (!$account instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$request->isMethod('POST')) {
            return $this->render('admin/account/index.html.twig', ['account' => $account]);
        }

        $this->denyUnlessTokenIsValid($request);

        $error = $this->reasonToRefuse($account, $request);

        if (null !== $error) {
            return $this->render('admin/account/index.html.twig', [
                'account' => $account,
                'error' => $error,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash('success', 'Your password has been changed.');

        return $this->redirectToRoute('admin_account');
    }

    /**
     * @return string|null the sentence to show, or null when the change was made
     */
    private function reasonToRefuse(User $account, Request $request): ?string
    {
        $new = (string) $request->request->get('password');

        if (mb_strlen($new) < PasswordResetController::MINIMUM_PASSWORD_LENGTH) {
            return 'A password needs at least '.PasswordResetController::MINIMUM_PASSWORD_LENGTH.' characters.';
        }

        if ($new !== (string) $request->request->get('confirmation')) {
            return 'The two new passwords do not match.';
        }

        // Last, and only once the new password is known to be acceptable — so
        // that a mistyped confirmation does not cost somebody their attempt at
        // proving who they are.
        if (!$this->resets->change($account, (string) $request->request->get('currentPassword'), $new)) {
            return 'That is not your current password.';
        }

        return null;
    }

    private function denyUnlessTokenIsValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid('change-password', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
