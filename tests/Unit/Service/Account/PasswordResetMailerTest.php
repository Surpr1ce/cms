<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Account;

use App\Entity\User;
use App\Service\Account\PasswordResetMailer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The reset link comes from configuration, whatever the router was pointed at.
 *
 * This is the assertion behind the worst finding this project has had. The audit
 * after feature 017 found that `generate(..., ABSOLUTE_URL)` takes its host from
 * the router's context, and inside a request that context is filled from the
 * incoming `Host:` header — so a stranger could ask for an administrator's
 * address with `Host: attacker.example`, the administrator would receive a
 * genuine email *from this site*, and one click would hand a live token to
 * whoever asked. Every other control on the feature is bypassed rather than
 * weakened by that, because the token is given away rather than guessed.
 *
 * It was closed twice — trusted hosts, and the link built from `DEFAULT_URI` —
 * and until this file the second defence was only ever exercised through a
 * functional test that had to boot a kernel to say so. Now the class is
 * constructible with three arguments, the router is handed a context pointing at
 * an attacker's host on purpose, and the assertion is one line: the link names
 * the configured site.
 */
final class PasswordResetMailerTest extends TestCase
{
    private const string SITE = 'https://cms.example';

    private const string ATTACKER = 'attacker.example';

    public function testTheLinkNamesTheConfiguredSiteAndNotWhateverTheRouterWasPointedAt(): void
    {
        $link = $this->sendUsing($this->router())->getContext()['link'] ?? null;

        self::assertSame(
            self::SITE.'/reset-password/a-token',
            $link,
            'The link was built from the router context rather than from configuration.',
        );
    }

    /**
     * The router is shared, and the swap happens mid-request. A context left
     * pointing at the configured site would send every *other* absolute URL in
     * that request somewhere it does not belong.
     */
    public function testTheRoutersOwnContextIsPutBackAfterwards(): void
    {
        $router = $this->router();

        $this->sendUsing($router);

        self::assertSame(self::ATTACKER, $router->getContext()->getHost());
    }

    public function testTheMessageGoesToTheAccountAndNamesItsTemplateRatherThanRenderingIt(): void
    {
        $message = $this->sendUsing($this->router());

        self::assertSame('somebody@example.com', $message->getTo()[0]->getAddress());
        self::assertSame('Set a new password', $message->getSubject());
        self::assertSame('email/reset_password.txt.twig', $message->getTextTemplate());

        // Nothing rendered here: the body is still empty at this point, and the
        // mailer's own listener fills it. That is what keeps Twig out of this
        // layer — see ADR 13's amendment.
        self::assertNull($message->getTextBody());
    }

    /**
     * A context pointing somewhere this site is not, which is exactly what an
     * attacker-controlled `Host:` header produces inside a real request.
     */
    private function router(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('password_reset_complete', new Route('/reset-password/{token}'));

        return new UrlGenerator($routes, new RequestContext('', 'GET', self::ATTACKER, 'https'));
    }

    /**
     * Sends one reset email through a mailer that keeps the message instead of
     * delivering it, and hands back what was built.
     *
     * The fake is created here rather than in a helper of its own so that its
     * anonymous type — and therefore `$sent` — is visible to the analyser; a
     * helper returning `MailerInterface` would hide the one property this file
     * reads.
     */
    private function sendUsing(UrlGenerator $router): TemplatedEmail
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };

        $account = new User('somebody@example.com', 'Somebody', new DateTimeImmutable('2026-01-01 00:00:00'));

        new PasswordResetMailer($mailer, $router, self::SITE)->send($account, 'a-token');

        $message = $mailer->sent;
        self::assertInstanceOf(TemplatedEmail::class, $message);

        return $message;
    }
}
