<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Csp\NonceGenerator;

use function implode;
use function sprintf;
use function str_contains;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The headers every response carries.
 *
 * One place, so that "which responses are protected" has the answer "all of
 * them" rather than a list. The alternative — setting headers in controllers, or
 * in a base template — protects whatever somebody remembered, and the error
 * pages have no controller of ours at all.
 *
 * The policy is the second layer under the one feature 004 built. Sanitising
 * decides what is stored; this decides what runs if something hostile is stored
 * anyway, whether because the allow-list was wrong, because content predates the
 * sanitiser, or because a template gained a mistake. Neither substitutes for the
 * other: a policy alone leaves defacement possible, and sanitising alone assumes
 * it is correct.
 *
 * @see \App\Service\Content\ContentSanitiser the first layer
 */
final readonly class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private NonceGenerator $nonces)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    /**
     * A new request is a new nonce.
     *
     * Main requests only. A sub-request is part of the same page and has to
     * keep the value the surrounding policy names.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->nonces->forget();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        // Sub-requests are deliberately included, and this is not an oversight.
        // When a controller throws, Symfony runs the error controller as a
        // sub-request and that sub-response *becomes* the response — the main
        // request never reaches this event at all. Guarding on
        // `isMainRequest()` therefore delivers every 404 and every 500 with no
        // policy on it, which was exactly what happened here until a test asked
        // about the 404. A fragment's headers are discarded, so including them
        // costs nothing.
        $response = $event->getResponse();

        // Downloads and images served by MediaController are not documents and
        // a policy on them protects nothing. The type check rather than a route
        // check, because the rule is about what the bytes are.
        $type = (string) $response->headers->get('Content-Type', '');
        $isDocument = '' === $type || str_contains($type, 'text/html');

        // FR-013. Already set by MediaController on uploads, and set here for
        // everything else, because "the type we declared is the type we mean"
        // is not a rule that should have exceptions.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // FR-014. `strict-origin-when-cross-origin` sends the full address
        // within the site, the origin alone to another site over HTTPS, and
        // nothing when leaving HTTPS for HTTP. An administration address can
        // name a draft, so the cross-site case matters here.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // FR-011, twice. `frame-ancestors` is the modern rule and the one that
        // counts; `X-Frame-Options` is for whatever does not read it yet. Both
        // say the same thing, which is the only safe way to say it twice.
        $response->headers->set('X-Frame-Options', 'DENY');

        if ($isDocument) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }
    }

    /**
     * @return non-empty-string
     */
    private function policy(): string
    {
        // The nonce is minted here rather than read, so a response whose
        // template never called `csp_nonce()` still gets a policy — it simply
        // names a nonce nothing uses, which forbids exactly as much.
        $nonce = $this->nonces->nonce();

        $directives = [
            // Everything not named below falls back to this.
            "default-src 'self'",

            // FR-008 and FR-009. No `unsafe-inline`: the three inline scripts
            // the asset importmap emits carry this nonce, and the generic
            // administration templates mark their own through `csp_nonce()`.
            // Anything else inline — including a script that survived
            // sanitising and reached a reader's page — does not run.
            sprintf("script-src 'self' 'nonce-%s'", $nonce),

            // No `unsafe-inline`, as of feature 016.
            //
            // It was here because the generic administration screens carried
            // style attributes on elements this project did not author, and an
            // attribute cannot be marked with a nonce. Those screens were
            // replaced by hand-written ones for reasons that had nothing to do
            // with this — they looked like a different product — and the
            // concession they required went with them.
            //
            // Worth noticing as a general point: the exception was documented,
            // justified and correct, and it still stopped being needed the
            // moment the thing that needed it was gone. A recorded concession is
            // worth re-reading whenever the reason for it changes.
            "style-src 'self'",

            // `data:` for the favicon, which is an inline SVG in the layout.
            "img-src 'self' data:",

            "font-src 'self'",
            "connect-src 'self'",

            // Plugins. Nothing here uses any, and `default-src` would not cover
            // this in older browsers.
            "object-src 'none'",

            // Stops injected markup rewriting what relative addresses resolve
            // against, which would otherwise turn every relative script source
            // on the page into a source the attacker controls.
            "base-uri 'self'",

            // Where a form on this site may post. Without it, injected markup
            // can put a form on a real administration page and collect what is
            // typed into it.
            "form-action 'self'",

            // FR-011.
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }
}
