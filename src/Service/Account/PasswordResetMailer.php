<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * The one email this application sends.
 *
 * It lived in `PasswordResetController` until the architecture review: the
 * controller built the message, rendered its body and generated the link, which
 * made a delivery boundary the owner of what a reset email says. Two concrete
 * costs, beyond the rule in `CLAUDE.md` that a controller only translates —
 * nothing else could send this mail (a console command reissuing a link would
 * have had to build it again), and the security-critical part of it, `linkFor()`,
 * could only be tested through an HTTP request.
 *
 * The body is a template *named* on the message rather than rendered here.
 * `TemplatedEmail` is rendered by the mailer's own listener, so this class chooses
 * the wording without doing any rendering — but naming a template is still a
 * step towards delivery, and this is the third place the domain takes one.
 * {@see docs/adr/0013-two-places-where-the-domain-knows-about-delivery.md}, whose
 * amendment records it, and `LayeringTest::testTheExceptionsRecordedInAdr13HaveNotGrown()`,
 * which pins this file as the only one allowed to.
 */
final readonly class PasswordResetMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        // Where this site actually lives, from configuration. See linkFor().
        private string $siteUri,
    ) {
    }

    public function send(User $account, string $token): void
    {
        $this->mailer->send(
            new TemplatedEmail()
                ->to(new Address($account->getEmail(), $account->getDisplayName()))
                ->subject('Set a new password')
                ->textTemplate('email/reset_password.txt.twig')
                ->context([
                    'displayName' => $account->getDisplayName(),
                    'link' => $this->linkFor($token),
                ]),
        );
    }

    /**
     * The reset link, built from configuration rather than from the request.
     *
     * This is the one place in the application where that distinction is worth a
     * paragraph. `generate(..., ABSOLUTE_URL)` takes its host from the router's
     * context, and inside a request that context is filled from the incoming
     * `Host:` header. So an attacker could ask for somebody else's address with
     * `Host: attacker.example`, and the victim would receive a genuine email from
     * this site whose link pointed at the attacker's server — handing over a live
     * token, which the reset screen accepts and turns straight into a session.
     * Every other control (hashed at rest, single use, one hour, throttled,
     * identical responses) is bypassed rather than weakened, because the token is
     * given away rather than guessed.
     *
     * `trusted_hosts` closes it too and is set, but this closes it whatever the
     * web server in front happens to pass through — a mail that leaves the
     * building deserves the belt as well as the braces.
     *
     * The context is swapped and put back rather than a second generator being
     * built: the router is a shared service, PHP handles one request at a time,
     * and `finally` means an exception cannot leave it pointing at the wrong site.
     */
    private function linkFor(string $token): string
    {
        $original = $this->urlGenerator->getContext();
        $this->urlGenerator->setContext(RequestContext::fromUri($this->siteUri));

        try {
            return $this->urlGenerator->generate(
                'password_reset_complete',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
        } finally {
            $this->urlGenerator->setContext($original);
        }
    }
}
