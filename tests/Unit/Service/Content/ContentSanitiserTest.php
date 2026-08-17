<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Content;

use App\Service\Content\ContentSanitiser;

use const ENT_HTML5;
use const ENT_QUOTES;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of hostile markup, and the formatting that must survive it.
 *
 * These assert on the sanitiser's output directly — never on a rendered page.
 * A test that loads an admin screen and checks no script ran would pass with the
 * sanitiser deleted, because Twig escapes by default; it would be testing Twig.
 * The question here is what gets *stored*, because that is what a reader
 * eventually receives.
 */
final class ContentSanitiserTest extends TestCase
{
    /**
     * Each entry is markup somebody might submit and a fragment that must not
     * survive it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hostileMarkupProvider(): iterable
    {
        yield 'a script element' => [
            '<p>Fine.</p><script>alert(1)</script>',
            'alert(1)',
        ];
        yield 'a script with attributes' => [
            '<script type="text/javascript" src="https://elsewhere.example/x.js"></script>',
            'script',
        ];
        yield 'an onclick handler' => [
            '<p onclick="steal()">Click me</p>',
            'onclick',
        ];
        yield 'an onerror handler on an image' => [
            '<img src="x" onerror="steal()">',
            'onerror',
        ];
        yield 'an onload handler' => [
            '<img src="https://example.com/a.png" onload="steal()">',
            'onload',
        ];
        yield 'a javascript link' => [
            '<a href="javascript:steal()">Read more</a>',
            'javascript:',
        ];
        yield 'a javascript link with mixed case' => [
            '<a href="JaVaScRiPt:steal()">Read more</a>',
            'steal()',
        ];
        yield 'a data URL in a link' => [
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">Read more</a>',
            'data:text/html',
        ];
        yield 'a data URL in an image' => [
            '<img src="data:text/html,<script>alert(1)</script>">',
            'data:text/html',
        ];
        yield 'an inline frame' => [
            '<iframe src="https://elsewhere.example"></iframe>',
            'iframe',
        ];
        yield 'an object' => [
            '<object data="https://elsewhere.example/x.swf"></object>',
            'object',
        ];
        yield 'an embed' => [
            '<embed src="https://elsewhere.example/x.swf">',
            'embed',
        ];
        yield 'a form' => [
            '<form action="https://elsewhere.example"><input name="password"></form>',
            '<form',
        ];
        yield 'a style element' => [
            '<style>body { display: none }</style>',
            'display: none',
        ];
        yield 'a base element that would rewrite every relative link' => [
            '<base href="https://elsewhere.example/">',
            '<base',
        ];
        yield 'a meta refresh' => [
            '<meta http-equiv="refresh" content="0;url=https://elsewhere.example">',
            'refresh',
        ];
        yield 'a stylesheet link' => [
            '<link rel="stylesheet" href="https://elsewhere.example/x.css">',
            'stylesheet',
        ];
        yield 'an svg carrying script' => [
            '<svg><script>alert(1)</script></svg>',
            'alert(1)',
        ];
        yield 'an unclosed script' => [
            '<p>Fine.</p><script>alert(1)',
            'alert(1)',
        ];
        yield 'a script split by a comment' => [
            '<scr<!-- -->ipt>alert(1)</script>',
            'alert(1)',
        ];
        yield 'an animated svg handler' => [
            '<svg><animate onbegin="steal()" attributeName="x"></animate></svg>',
            'onbegin',
        ];
        yield 'a details toggle handler' => [
            '<details ontoggle="steal()" open>x</details>',
            'ontoggle',
        ];
        yield 'a button with a handler' => [
            '<button onclick="steal()">Go</button>',
            'onclick',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legitimateMarkupProvider(): iterable
    {
        yield 'a paragraph' => ['<p>An ordinary paragraph.</p>', '<p>'];
        yield 'a heading' => ['<h2>A heading</h2>', '<h2>'];
        yield 'a subheading' => ['<h3>A subheading</h3>', '<h3>'];
        yield 'strong text' => ['<p><strong>Important</strong></p>', '<strong>'];
        yield 'emphasis' => ['<p><em>Emphasised</em></p>', '<em>'];
        yield 'an unordered list' => ['<ul><li>One</li><li>Two</li></ul>', '<ul>'];
        yield 'an ordered list' => ['<ol><li>One</li></ol>', '<ol>'];
        yield 'a block quote' => ['<blockquote><p>Quoted.</p></blockquote>', '<blockquote>'];
        yield 'inline code' => ['<p><code>$x = 1;</code></p>', '<code>'];
        yield 'a code block' => ['<pre><code>function f() {}</code></pre>', '<pre>'];
        yield 'a table' => ['<table><tr><td>One</td></tr></table>', '<table>'];
        yield 'a table header cell' => ['<table><tr><th scope="col">One</th></tr></table>', '<th'];
        yield 'a horizontal rule' => ['<p>A</p><hr><p>B</p>', '<hr'];
        yield 'a line break' => ['<p>One<br>Two</p>', '<br'];
        yield 'a figure' => ['<figure><figcaption>A caption</figcaption></figure>', '<figure>'];
    }

    #[DataProvider('hostileMarkupProvider')]
    public function testHostileMarkupDoesNotSurvive(string $submitted, string $mustNotSurvive): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            $mustNotSurvive,
            new ContentSanitiser()->sanitiseMarkup($submitted),
        );
    }

    #[DataProvider('legitimateMarkupProvider')]
    public function testOrdinaryFormattingSurvives(string $submitted, string $mustSurvive): void
    {
        self::assertStringContainsString(
            $mustSurvive,
            new ContentSanitiser()->sanitiseMarkup($submitted),
        );
    }

    public function testAnHttpLinkSurvivesWithItsTarget(): void
    {
        $sanitised = new ContentSanitiser()->sanitiseMarkup('<a href="https://example.com/a">Read more</a>');

        self::assertStringContainsString('https://example.com/a', $sanitised);
        self::assertStringContainsString('Read more', $sanitised);
    }

    /**
     * The sanitiser writes the address back as `mailto:someone&#64;example.com`.
     * That is correct HTML and a browser follows it identically, so the
     * assertion decodes before comparing rather than demanding a particular
     * spelling of the same link.
     */
    public function testAMailtoLinkSurvives(): void
    {
        $sanitised = new ContentSanitiser()->sanitiseMarkup('<a href="mailto:someone@example.com">Write</a>');

        self::assertStringContainsString(
            'mailto:someone@example.com',
            html_entity_decode($sanitised, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    public function testAnImageSurvivesWithItsAlternativeText(): void
    {
        $sanitised = new ContentSanitiser()->sanitiseMarkup(
            '<img src="https://example.com/a.png" alt="A description">',
        );

        self::assertStringContainsString('https://example.com/a.png', $sanitised);
        self::assertStringContainsString('A description', $sanitised);
    }

    /**
     * The text around hostile markup is the author's work and must not be lost
     * along with the attack.
     */
    public function testTheSurroundingTextSurvivesTheRemoval(): void
    {
        $sanitised = new ContentSanitiser()->sanitiseMarkup(
            '<p>Before.</p><script>alert(1)</script><p>After.</p>',
        );

        self::assertStringContainsString('Before.', $sanitised);
        self::assertStringContainsString('After.', $sanitised);
        self::assertStringNotContainsString('alert(1)', $sanitised);
    }

    /**
     * A dropped script takes its contents with it. Unwrapping it instead would
     * leave the source code as visible text in the middle of the article.
     */
    public function testAScriptTakesItsContentsWithIt(): void
    {
        self::assertStringNotContainsString(
            'alert',
            new ContentSanitiser()->sanitiseMarkup('<script>alert("still here")</script>'),
        );
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', new ContentSanitiser()->sanitiseMarkup(''));
    }

    public function testPlainTextWithNoMarkupIsUnchanged(): void
    {
        self::assertStringContainsString(
            'Just some words.',
            new ContentSanitiser()->sanitiseMarkup('Just some words.'),
        );
    }

    /**
     * FR-004: sanitising is idempotent, which is what makes "sanitise once, on
     * the way in" safe. If a second pass changed anything, re-saving an article
     * would slowly erode it.
     */
    public function testSanitisingTwiceChangesNothingTheSecondTime(): void
    {
        $sanitiser = new ContentSanitiser();
        $once = $sanitiser->sanitiseMarkup('<h2>A heading</h2><p>With <a href="https://example.com">a link</a>.</p>');

        self::assertSame($once, $sanitiser->sanitiseMarkup($once));
    }

    // --- titles and summaries, which are never markup ---

    public function testATitleKeepsItsWordsAndLosesItsTags(): void
    {
        self::assertSame(
            'Hello World',
            new ContentSanitiser()->sanitiseText('<strong>Hello</strong> World'),
        );
    }

    public function testATitleCannotCarryAScript(): void
    {
        $sanitised = new ContentSanitiser()->sanitiseText('Title<script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $sanitised);
        self::assertStringNotContainsString('<', $sanitised);
    }

    /**
     * Entities are decoded before the second strip, so markup smuggled in as
     * "&lt;script&gt;" cannot come back to life in a template that ever uses
     * |raw.
     */
    public function testAnEntityEncodedTagDoesNotSurviveInATitle(): void
    {
        self::assertStringNotContainsString(
            '<script',
            new ContentSanitiser()->sanitiseText('Title &lt;script&gt;alert(1)&lt;/script&gt;'),
        );
    }

    public function testATitleIsTrimmed(): void
    {
        self::assertSame('A Title', new ContentSanitiser()->sanitiseText('   A Title   '));
    }

    public function testAnOrdinaryTitleIsUntouched(): void
    {
        self::assertSame(
            'Symfony 8.1 arrives with a slimmer kernel',
            new ContentSanitiser()->sanitiseText('Symfony 8.1 arrives with a slimmer kernel'),
        );
    }
}
