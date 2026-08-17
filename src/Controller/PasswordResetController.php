<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PasswordResetRequest;
use App\Service\Account\PasswordResetService;

use function mb_strlen;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;

use function trim;

/**
 * Getting back in.
 *
 * The rule this controller exists to keep is FR-004: **the response is the same
 * whether or not the address holds an account.** Not similar — the same page,
 * the same wording, the same status. A reset form that says "we have sent you an
 * email" for one address and "no account found" for another is a way to test any
 * list of addresses against this installation, and the honest-sounding message is
 * the wrong one.
 *
 * That is why nothing here branches on the result of `request()`. The service
 * answers with a token or with null; this sends an email in the first case and
 * does nothing in the second, and then renders the identical page either way.
 */
final class PasswordResetController extends AbstractController
{
    /**
     * The same length the administration screen asks for. One number, in as few
     * places as possible.
     */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly MailerInterface $mailer,
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
        private readonly UserAuthenticatorInterface $authenticator,
        // Named explicitly: the form-login authenticator is registered per
        // firewall, so there is no class to autowire — `security.authenticator.
        // form_login.main` is the one belonging to the `main` firewall, and
        // config/services.yaml says so.
        private readonly AuthenticatorInterface $formLogin,
    ) {
    }

    #[Route('/reset-password', name: 'password_reset_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('public/security/reset_request.html.twig');
        }

        if (!$this->isCsrfTokenValid('password-reset', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $email = trim((string) $request->request->get('email'));

        // Per client address, so that this form cannot be used to send mail to
        // somebody else's inbox on demand. The refusal is a page rather than a
        // 429 body, because whoever is being protected here is the person whose
        // address is being used, not the one asking.
        if (!$this->passwordResetLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->render('public/security/reset_request.html.twig', [
                'tooMany' => true,
            ]);
        }

        $started = $this->resets->request($email);

        if (null !== $started) {
            [$account, $token] = $started;

            $this->mailer->send(
                new Email()
                    ->to(new Address($account->getEmail(), $account->getDisplayName()))
                    ->subject('Set a new password')
                    ->text($this->renderView('email/reset_password.txt.twig', [
                        'displayName' => $account->getDisplayName(),
                        'link' => $this->generateUrl(
                            'password_reset_complete',
                            ['token' => $token],
                            UrlGeneratorInterface::ABSOLUTE_URL,
                        ),
                    ])),
            );
        }

        // The same page, whichever happened. Rendered rather than redirected, so
        // that the two cases cannot even differ by a redirect target.
        return $this->render('public/security/reset_request.html.twig', [
            'sent' => true,
        ]);
    }

    /**
     * The link.
     *
     * Invalid, expired, used and superseded all arrive here as null, and all get
     * the same refusal — telling them apart tells whoever holds a stolen link
     * what kind of stolen link they have.
     */
    #[Route(
        '/reset-password/{token}',
        name: 'password_reset_complete',
        requirements: ['token' => '[0-9a-f]{32}'],
        methods: ['GET', 'POST'],
    )]
    public function complete(string $token, Request $request): Response
    {
        $reset = $this->resets->findUsable($token);

        if (!$reset instanceof PasswordResetRequest) {
            return $this->render(
                'public/security/reset_refused.html.twig',
                [],
                new Response(status: Response::HTTP_NOT_FOUND),
            );
        }

        if (!$request->isMethod('POST')) {
            return $this->render('public/security/reset_complete.html.twig', ['token' => $token]);
        }

        if (!$this->isCsrfTokenValid('password-reset-complete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $password = (string) $request->request->get('password');
        $error = $this->reasonToRefuse($password, (string) $request->request->get('confirmation'));

        if (null !== $error) {
            return $this->render('public/security/reset_complete.html.twig', [
                'token' => $token,
                'error' => $error,
            ]);
        }

        $account = $this->resets->complete($reset, $password);

        $this->addFlash('success', 'Your password has been changed.');

        // Signed in only now, and only after the password is stored. The
        // alternative is showing a sign-in form to somebody who has just proved
        // they hold the address.
        return $this->authenticator->authenticateUser($account, $this->formLogin, $request)
            ?? $this->redirectToRoute('admin_dashboard');
    }

    /**
     * @return string|null the sentence to show, or null when the password is
     *                     acceptable
     */
    private function reasonToRefuse(string $password, string $confirmation): ?string
    {
        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            return 'A password needs at least '.self::MINIMUM_PASSWORD_LENGTH.' characters.';
        }

        if ($password !== $confirmation) {
            return 'The two passwords do not match.';
        }

        return null;
    }
}
