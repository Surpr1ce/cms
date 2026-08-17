<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Content;

use App\Service\Content\ContentSanitiser;

use function count;

use PHPUnit\Framework\TestCase;

use function preg_match_all;
use function sort;
use function sprintf;

/**
 * The toolbar and the allow-list, read against each other.
 *
 * ADR 14 claims that adding a button to the visual editor "means adding an
 * element to the allow-list first, or the test fails". A review pointed out that
 * this was not true: the sanitiser test next door is a hand-written list of HTML
 * strings with no connection to `assets/editor.js`, so a new button left the
 * suite perfectly green. The claim was a convention describing itself as a check.
 *
 * This is the check. It reads the toolbar's own definition out of the JavaScript
 * and asserts two things:
 *
 * **Every block element the toolbar can apply survives sanitising.** Extracted
 * from the `value` of each command, so a button that formats as `<section>`
 * fails here rather than silently discarding an editor's work at save time.
 *
 * **The set of commands is the set somebody agreed to.** There is no element to
 * extract from `bold` or `insertUnorderedList`, so that half is pinned by
 * enumeration: adding any button at all fails this test until the list below is
 * updated deliberately, which is the moment to ask what markup it produces.
 *
 * Reading a JavaScript file from a PHP test is unusual and is the point — it is
 * the only way the two can be compared at all without a JavaScript test runner,
 * which this project deliberately does not have.
 */
final class ToolbarMatchesTheAllowListTest extends TestCase
{
    private const string EDITOR = __DIR__.'/../../../../assets/editor.js';

    /**
     * Every command the toolbar offers. `link` and `removeFormat` produce no
     * element of their own — the first is covered by the sanitiser's link-scheme
     * rules and the second only removes things.
     *
     * @var list<string>
     */
    private const array EXPECTED_COMMANDS = [
        'bold',
        'formatBlock',
        'insertOrderedList',
        'insertUnorderedList',
        'italic',
        'link',
        'removeFormat',
    ];

    /**
     * What the commands with no `value` produce, which cannot be read out of the
     * file. `execCommand` writes tags rather than styles because `styleWithCSS`
     * is set to false — the elements below are what that produces.
     *
     * @var list<string>
     */
    private const array ELEMENTS_FROM_INLINE_COMMANDS = ['b', 'i', 'ul', 'ol', 'li'];

    public function testEveryBlockTheToolbarAppliesSurvivesSanitising(): void
    {
        $blocks = $this->blocksTheToolbarApplies();

        self::assertNotEmpty($blocks, 'No block commands were found — has editor.js moved?');

        $sanitiser = new ContentSanitiser();

        foreach ($blocks as $element) {
            $written = sprintf('<%s>Some words</%s>', $element, $element);

            self::assertSame(
                $written,
                $sanitiser->sanitiseMarkup($written),
                sprintf('The toolbar can apply <%s>, which ContentSanitiser does not keep.', $element),
            );
        }
    }

    public function testTheElementsTheInlineCommandsProduceSurviveSanitising(): void
    {
        $sanitiser = new ContentSanitiser();

        // Each element in the markup it legitimately appears in. A list item
        // inside a paragraph is not valid HTML and the parser hoists it out,
        // which would fail this test for a reason that has nothing to do with
        // the allow-list.
        $samples = [
            'b' => '<p><b>Some words</b></p>',
            'i' => '<p><i>Some words</i></p>',
            'ul' => '<ul><li>One</li></ul>',
            'ol' => '<ol><li>One</li></ol>',
            'li' => '<ul><li>One</li></ul>',
        ];

        foreach (self::ELEMENTS_FROM_INLINE_COMMANDS as $element) {
            self::assertArrayHasKey($element, $samples, sprintf('No sample markup for <%s>.', $element));

            $written = $samples[$element];

            self::assertSame($written, $sanitiser->sanitiseMarkup($written));
        }
    }

    /**
     * The enumeration half. This failing means a button was added; the fix is to
     * add it here *and* to satisfy yourself that whatever it writes survives the
     * allow-list, which is exactly the question the ADR wants asked.
     */
    public function testTheToolbarOffersNoCommandNobodyAgreedTo(): void
    {
        $found = $this->commandsTheToolbarOffers();

        self::assertSame(
            self::EXPECTED_COMMANDS,
            $found,
            'The editor toolbar changed. Check what the new command writes before listing it here.',
        );
    }

    /**
     * @return list<string> the element names, without their angle brackets
     */
    private function blocksTheToolbarApplies(): array
    {
        preg_match_all("/value:\\s*'<(\\w+)>'/", $this->editorSource(), $matches);

        $blocks = array_values(array_unique($matches[1]));
        sort($blocks);

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function commandsTheToolbarOffers(): array
    {
        preg_match_all("/command:\\s*'(\\w+)'/", $this->editorSource(), $matches);

        $commands = array_values(array_unique($matches[1]));
        sort($commands);

        return $commands;
    }

    private function editorSource(): string
    {
        $source = file_get_contents(self::EDITOR);

        self::assertIsString($source, 'assets/editor.js could not be read.');
        self::assertGreaterThan(0, count(self::EXPECTED_COMMANDS));

        return $source;
    }
}
