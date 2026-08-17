<?php

declare(strict_types=1);

namespace App\Service\Seo;

use const ENT_HTML5;
use const ENT_QUOTES;

use function html_entity_decode;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function strip_tags;
use function trim;

/**
 * Turns a body into the one short line a preview or a feed summary needs.
 *
 * Three deliveries want the same thing and none of them can take markup: a
 * `description` tag, an Open Graph description and a feed summary. The rules are
 * the same for all three, so they are written once.
 *
 * Cutting is done on a word boundary. A description that ends mid-word reads as
 * broken software rather than as an abbreviation, and it is the first thing
 * somebody sees of an article they have not read.
 */
final readonly class PlainText
{
    /**
     * Long enough to be useful, short enough that the major networks and search
     * engines show all of it. Beyond roughly this they truncate, and a summary
     * truncated by somebody else ends wherever they choose.
     */
    public const int PREVIEW_LENGTH = 160;

    /**
     * @param int<1, max> $limit
     */
    public function summarise(?string $html, int $limit = self::PREVIEW_LENGTH): string
    {
        if (null === $html) {
            return '';
        }

        // Tags first, then entities. The other way round would turn a stored
        // `&lt;script&gt;` back into a tag and then strip it, which quietly
        // deletes text an author wrote on purpose.
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        // Every run of whitespace — including the newlines that markup leaves
        // behind — becomes one space. A description containing a newline is
        // invalid in an attribute and unreadable in a feed.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return $this->cutOnAWordBoundary($text, $limit);
    }

    /**
     * @param int<1, max> $limit
     */
    private function cutOnAWordBoundary(string $text, int $limit): string
    {
        // One character past the limit, so that a limit falling exactly on a
        // space is recognised as a boundary rather than as a cut word.
        $cut = mb_substr($text, 0, $limit + 1);
        $lastSpace = mb_strrpos($cut, ' ');

        // No space at all: one word longer than the whole limit. Cutting it is
        // the only option, and an ellipsis says that it was cut.
        $kept = false === $lastSpace ? mb_substr($text, 0, $limit) : mb_substr($cut, 0, $lastSpace);

        return rtrim($kept, " \t\n\r\0\x0B.,;:!?-").'…';
    }
}
