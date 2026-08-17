<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Seo;

use App\Service\Seo\PlainText;

use function mb_strlen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The one line that stands in for a whole article.
 *
 * It appears in a `description` tag, in an Open Graph description and in a feed
 * summary — three places where markup is either invalid or displayed literally,
 * and where a newline breaks the document. So the rules are strict and they are
 * tested here rather than three times over in functional tests.
 */
final class PlainTextTest extends TestCase
{
    private PlainText $plainText;

    protected function setUp(): void
    {
        $this->plainText = new PlainText();
    }

    public function testMarkupIsRemovedRatherThanEscaped(): void
    {
        $summary = $this->plainText->summarise('<p>An <strong>emphatic</strong> sentence.</p>');

        self::assertSame('An emphatic sentence.', $summary);
    }

    /**
     * Tags are stripped before entities are decoded. The other order turns a
     * stored `&lt;script&gt;` back into a tag and then deletes it, silently
     * removing text somebody wrote on purpose.
     */
    public function testAnEscapedTagSurvivesAsText(): void
    {
        $summary = $this->plainText->summarise('<p>Use the &lt;article&gt; element.</p>');

        self::assertSame('Use the <article> element.', $summary);
    }

    public function testEntitiesBecomeTheCharactersTheyStandFor(): void
    {
        $summary = $this->plainText->summarise('<p>Tea &amp; biscuits &mdash; always.</p>');

        self::assertSame('Tea & biscuits — always.', $summary);
    }

    /**
     * A newline is invalid inside an attribute and unreadable in a feed.
     */
    public function testEveryRunOfWhitespaceBecomesOneSpace(): void
    {
        $summary = $this->plainText->summarise("<p>First line.</p>\n\n<p>Second    line.</p>");

        self::assertSame('First line. Second line.', $summary);
        self::assertStringNotContainsString("\n", $summary);
    }

    public function testAnEmptyBodyProducesAnEmptyLine(): void
    {
        self::assertSame('', $this->plainText->summarise(''));
        self::assertSame('', $this->plainText->summarise(null));
        self::assertSame('', $this->plainText->summarise('<p></p>'));
    }

    public function testSomethingShorterThanTheLimitIsLeftEntirelyAlone(): void
    {
        $summary = $this->plainText->summarise('<p>Short enough.</p>');

        self::assertSame('Short enough.', $summary);
        self::assertStringNotContainsString('…', $summary);
    }

    public function testSomethingLongerIsCutAndSaysSo(): void
    {
        $summary = $this->plainText->summarise('<p>'.str_repeat('word ', 100).'</p>', 40);

        self::assertLessThanOrEqual(41, mb_strlen($summary));
        self::assertStringEndsWith('…', $summary);
    }

    /**
     * The assertion this class exists for. A description ending mid-word reads
     * as broken software, and it is the first thing anybody sees of an article
     * they have not read.
     */
    /**
     * @param int<1, max> $limit
     */
    #[DataProvider('bodiesThatWouldBeCutMidWord')]
    public function testACutNeverFallsInsideAWord(string $body, int $limit): void
    {
        $summary = $this->plainText->summarise($body, $limit);

        self::assertMatchesRegularExpression(
            '/(^|\s)\S+…$/u',
            $summary,
            'The summary ends part-way through a word: '.$summary,
        );
    }

    /**
     * @return iterable<string, array{string, int<1, max>}>
     */
    public static function bodiesThatWouldBeCutMidWord(): iterable
    {
        yield 'a limit landing inside a word' => ['The quick brown fox jumps over the lazy dog', 12];
        yield 'a limit landing on a space' => ['The quick brown fox jumps over the lazy dog', 9];
        yield 'a limit landing just after a space' => ['The quick brown fox jumps over the lazy dog', 10];
        yield 'long words' => ['Antidisestablishmentarianism notwithstanding, however', 20];
    }

    /**
     * One word longer than the whole limit. There is no boundary to cut on, so
     * the word is cut — but the ellipsis still says that it was.
     */
    public function testASingleWordLongerThanTheLimitIsStillCut(): void
    {
        $summary = $this->plainText->summarise(str_repeat('a', 200), 20);

        self::assertSame(21, mb_strlen($summary));
        self::assertStringEndsWith('…', $summary);
    }

    /**
     * Trailing punctuation before an ellipsis reads as a typing mistake.
     */
    public function testPunctuationIsNotLeftHangingBeforeTheEllipsis(): void
    {
        $summary = $this->plainText->summarise('One sentence ends here. And another begins', 23);

        self::assertStringEndsWith('…', $summary);
        self::assertStringNotContainsString('.…', $summary);
    }
}
