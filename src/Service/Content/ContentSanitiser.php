<?php

declare(strict_types=1);

namespace App\Service\Content;

use const ENT_HTML5;
use const ENT_QUOTES;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The one place markup a person typed is made safe.
 *
 * Every path that stores body text goes through here. That is not tidiness — it
 * is the requirement. Sanitising performed by each controller is sanitising that
 * one controller will eventually forget, and the failure is silent: the article
 * renders correctly, looks right in review, and runs somebody else's script in
 * the browser of the editor who opens it.
 *
 * Sanitising happens on the way in, so what is stored is what is served. The
 * cost — that tightening the policy does not clean content already stored — is
 * recorded in docs/adr/0010-sanitise-markup-on-the-way-in.md.
 *
 * The rules are an allow-list. Naming what is permitted rather than what is
 * forbidden is the only ordering that stays safe as browsers grow new features.
 */
final readonly class ContentSanitiser
{
    private HtmlSanitizer $sanitiser;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            // Structure and text
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('hr')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('b')
            ->allowElement('i')
            ->allowElement('s')
            ->allowElement('sup')
            ->allowElement('sub')
            ->allowElement('blockquote')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('figure')
            ->allowElement('figcaption')

            // Tables
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])

            // Links and images. The attributes are named individually; allowing
            // a whole element's attributes is how an event handler gets through.
            ->allowElement('a', ['href', 'title', 'rel'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])

            // Only these schemes may appear in a link or an image. javascript:
            // and data: are absent deliberately — the first executes, and the
            // second can carry markup that executes.
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])

            // Dropped entirely, contents and all, rather than unwrapped. An
            // unwrapped <script> would leave its source code as visible text in
            // the middle of the article.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('link')
            ->dropElement('meta')
            ->dropElement('base')

            // A ceiling, so a paste of something enormous cannot be stored.
            ->withMaxInputLength(2_000_000)
        ;

        $this->sanitiser = new HtmlSanitizer($config);
    }

    /**
     * Body text: markup, made safe.
     */
    public function sanitiseMarkup(string $markup): string
    {
        return trim($this->sanitiser->sanitize($markup));
    }

    /**
     * A title or a summary: never markup, whatever was typed.
     *
     * These are rendered as text everywhere, so storing markup in them would at
     * best show tags to a reader and at worst be rendered raw by a template
     * somebody writes later. Tags are stripped rather than escaped, so that what
     * is stored reads as the words the author meant.
     */
    public function sanitiseText(string $text): string
    {
        $stripped = strip_tags($text);

        // strip_tags leaves entities alone, so "&lt;script&gt;" would survive as
        // text and be decoded by a template that ever used |raw. Decoding first
        // and stripping again closes that.
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(strip_tags($decoded));
    }
}
