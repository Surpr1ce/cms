<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;

use function libxml_get_errors;
use function libxml_use_internal_errors;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a subscriber is told is new.
 *
 * The load-bearing test here is not any of the ones about ordering. It is
 * `testAHostileBodyCannotBreakTheDocument`: a feed is one document holding
 * twenty articles, so a single unclosed tag in a single body makes every entry
 * unreadable. That is why summaries are plain text rather than markup, and this
 * is the assertion that keeps it that way.
 */
final class FeedTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItIsServedAsAWellFormedAtomDocument(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        $this->client->request('GET', '/feed.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');
        $this->parse();
    }

    public function testEntriesAreNewestFirst(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'The older one',
            'createdAt' => new DateTimeImmutable('-1 month'),
        ]);
        ArticleFactory::new()->published()->create([
            'title' => 'The newer one',
            'createdAt' => new DateTimeImmutable('-1 day'),
        ]);

        $titles = $this->entryTitles();

        self::assertSame(['The newer one', 'The older one'], $titles);
    }

    /**
     * SC-002 again. A feed is served to nobody in particular — there is no
     * session, no voter and no viewer to filter for — so anything it can reach,
     * it publishes.
     */
    public function testNothingUnpublishedAppears(): void
    {
        ArticleFactory::createOne(['title' => 'A draft nobody should see']);
        ArticleFactory::new()->publishedThenArchived()->create(['title' => 'An archived article']);
        ArticleFactory::new()->published()->create(['title' => 'A published article']);

        self::assertSame(['A published article'], $this->entryTitles());
    }

    public function testEachEntryCarriesWhatAReaderNeeds(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'An article',
            'slug' => 'an-article',
            'excerpt' => 'A short summary of it.',
        ]);

        $xpath = $this->xpath();

        self::assertSame('An article', $this->firstValue($xpath, '//atom:entry/atom:title'));
        self::assertSame(
            'http://localhost/articles/an-article',
            $this->firstValue($xpath, '//atom:entry/atom:id'),
        );
        self::assertSame('A short summary of it.', $this->firstValue($xpath, '//atom:entry/atom:summary'));
        self::assertNotSame('', $this->firstValue($xpath, '//atom:entry/atom:published'));
        self::assertNotSame('', $this->firstValue($xpath, '//atom:entry/atom:author/atom:name'));
    }

    /**
     * FR-003 and FR-008. The document is read by something that is not a
     * browser sitting on this site, so a relative address means nothing.
     */
    public function testEveryAddressIsAbsolute(): void
    {
        ArticleFactory::new()->published()->many(2)->create();

        $xpath = $this->xpath();

        foreach ($xpath->query('//atom:link/@href') ?: [] as $href) {
            self::assertStringStartsWith('http', $href->nodeValue ?? '');
        }
    }

    /**
     * FR-009, and the assertion this file exists for.
     *
     * One document holds twenty articles. An unclosed tag in one body would
     * make the other nineteen unreadable, so nothing an author can type may
     * reach the document as markup.
     */
    public function testAHostileBodyCannotBreakTheDocument(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'Trouble & <strife>',
            'content' => '<p>An <unclosed tag, an & ampersand, a ]]> sequence and <![CDATA[ a CDATA opener.',
            'excerpt' => null,
        ]);
        ArticleFactory::new()->published()->create(['title' => 'An innocent bystander']);

        // Parses at all — which is the whole point — and the second article is
        // still readable, which is what would be lost if it did not.
        self::assertContains('An innocent bystander', $this->entryTitles());
    }

    /**
     * FR-010. Twenty, matching the front page, so that "the site" and "the
     * feed" mean the same thing to somebody comparing them.
     */
    public function testItIsLimitedToTheMostRecentEntries(): void
    {
        ArticleFactory::new()->published()->many(25)->create();

        self::assertCount(20, $this->entryTitles());
    }

    public function testASiteWithNothingPublishedStillProducesAValidFeed(): void
    {
        $this->client->request('GET', '/feed.xml');

        self::assertResponseIsSuccessful();
        $this->parse();
        self::assertSame([], $this->entryTitles());
    }

    /**
     * FR-011. A feed nobody can find is a feed nobody reads.
     */
    public function testEveryPageAdvertisesTheFeed(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        foreach (['/', '/articles/an-article'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertSame(
                'http://localhost/feed.xml',
                $crawler->filter('link[type="application/atom+xml"]')->attr('href'),
            );
        }
    }

    /**
     * A summary long enough to be a body would make the feed a copy of the
     * site, which is not what a summary is for.
     */
    public function testALongBodyBecomesAShortSummary(): void
    {
        ArticleFactory::new()->published()->create([
            'excerpt' => null,
            'content' => '<p>'.str_repeat('An overly long sentence. ', 200).'</p>',
        ]);

        $summary = $this->firstValue($this->xpath(), '//atom:entry/atom:summary');

        self::assertLessThan(200, mb_strlen($summary));
        self::assertStringNotContainsString('<', $summary);
    }

    /**
     * @return list<string>
     */
    private function entryTitles(): array
    {
        $xpath = $this->xpath();
        $titles = [];

        foreach ($xpath->query('//atom:entry/atom:title') ?: [] as $node) {
            // Through the node's own value rather than `textContent`, which the
            // union DOMXPath returns does not promise.
            $titles[] = (string) $node->nodeValue;
        }

        return $titles;
    }

    private function firstValue(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);

        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, 'Nothing matched '.$expression);

        return (string) $nodes->item(0)?->nodeValue;
    }

    private function xpath(): DOMXPath
    {
        $this->client->request('GET', '/feed.xml');
        self::assertResponseIsSuccessful();

        $xpath = new DOMXPath($this->parse());
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        return $xpath;
    }

    private function parse(): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $parsed = $document->loadXML((string) $this->client->getResponse()->getContent());

        $errors = libxml_get_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($parsed, 'The feed is not well-formed XML.');
        self::assertSame([], $errors, 'The feed parsed with errors.');

        return $document;
    }
}
