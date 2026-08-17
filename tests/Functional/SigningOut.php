<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Signing out the way a browser does.
 *
 * Logout is CSRF-protected, and the stateless token manager wants two things a
 * bare `POST /logout` from a test has neither of: a token value, and evidence
 * that the request came from a page of ours. A real browser supplies both — the
 * token because the form in the administration layout renders one, the evidence
 * because browsers send `Origin` on any POST.
 *
 * So this submits the actual form rather than posting to the address, and adds
 * the header BrowserKit does not. Eight tests used to post directly, and every
 * one of them silently stopped signing anybody out the moment the protection was
 * turned on — which is the correct behaviour, and exactly what the protection is
 * for: `<img src="…/logout">` on somebody else's page can no longer end an
 * editor's session.
 */
trait SigningOut
{
    private function signOut(?KernelBrowser $client = null): void
    {
        $client ??= $this->client;

        $crawler = $client->request('GET', '/admin');

        $client->submit(
            $crawler->selectButton('Sign out')->form(),
            [],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );
    }
}
