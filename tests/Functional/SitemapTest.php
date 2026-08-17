<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;

use function libxml_get_errors;
use function libxml_use_internal_errors;
use function parse_url;

use const PHP_URL_PATH;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a crawler is told exists.
 *
 * Two failures matter here and they pull in opposite directions. A sitemap that
 * lists a draft announces unpublished work to a search engine, which is the same
 * disclosure feature 002 spent itself preventing on the website. A sitemap that
 * lists an address answering 404 teaches a crawler to distrust the document, and
 * a comparison of two lists would never catch it.
 *
 * So this file does both: it looks for hidden titles in the output, and it
 * **requests every address the sitemap contains**.
 */
final class SitemapTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItIsServedAsWellFormedXml(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertParsesAsXml((string) $this->client->getResponse()->getContent());
    }

    public function testEveryPublishedAddressAppears(): void
    {
        $article = ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);
        $page = PageFactory::new()->published()->create(['slug' => 'a-published-page']);
        $section = CategoryFactory::createOne(['slug' => 'a-section']);
        $label = TagFactory::createOne(['slug' => 'a-label']);

        // A label is listed only when something carries it, so give it
        // something. An unused label lists nothing, and announcing an empty
        // page to a crawler is how a site acquires a reputation for thin
        // content.
        $article->addTag($label);
        $this->entityManager()->flush();

        $listed = $this->addressesInTheSitemap();

        self::assertContains('/', $listed);
        self::assertContains('/articles/'.$article->getSlug(), $listed);
        self::assertContains('/'.$page->getSlug(), $listed);
        self::assertContains('/sections/'.$section->getSlug(), $listed);
        self::assertContains('/topics/'.$label->getSlug(), $listed);
    }

    /**
     * SC-002, the same assertion feature 002 makes about the website.
     */
    public function testNothingUnpublishedAppears(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft-article']);
        ArticleFactory::new()->publishedThenArchived()->create(['slug' => 'an-archived-article']);
        PageFactory::createOne(['slug' => 'a-draft-page']);
        PageFactory::new()->publishedThenArchived()->create(['slug' => 'an-archived-page']);

        $listed = $this->addressesInTheSitemap();

        self::assertNotContains('/articles/a-draft-article', $listed);
        self::assertNotContains('/articles/an-archived-article', $listed);
        self::assertNotContains('/a-draft-page', $listed);
        self::assertNotContains('/an-archived-page', $listed);
    }

    /**
     * SC-003, and the assertion this file exists for.
     *
     * A sitemap listing a 404 is worse than no sitemap. Comparing two lists
     * would never find it — only asking the site does.
     */
    public function testEveryAddressItListsAnswersSuccessfully(): void
    {
        ArticleFactory::new()->published()->many(3)->create();
        PageFactory::new()->published()->many(2)->create();
        CategoryFactory::createMany(2);

        $listed = $this->addressesInTheSitemap();
        self::assertNotEmpty($listed);

        foreach ($listed as $address) {
            $this->client->request('GET', $address);

            self::assertResponseIsSuccessful(sprintf('The sitemap lists %s, which does not answer.', $address));
        }
    }

    /**
     * A brand new installation has nothing in it, and must still answer with a
     * document rather than an error.
     */
    public function testASiteWithNothingPublishedStillProducesAValidSitemap(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        $this->assertParsesAsXml((string) $this->client->getResponse()->getContent());
        self::assertSame(['/'], $this->addressesInTheSitemap());
    }

    public function testTheRobotsFileNamesTheSitemapAndClosesTheAdministrationArea(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');

        $content = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $content);
        self::assertStringContainsString('Disallow: /admin', $content);
    }

    /**
     * FR-003. The document is read somewhere other than this site, so a relative
     * address in it means nothing.
     */
    public function testEveryAddressIsAbsolute(): void
    {
        ArticleFactory::new()->published()->many(2)->create();

        $this->client->request('GET', '/sitemap.xml');

        foreach ($this->locations() as $location) {
            self::assertStringStartsWith('http', $location);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @return list<string>
     */
    private function locations(): array
    {
        $document = $this->assertParsesAsXml((string) $this->client->getResponse()->getContent());

        $locations = [];

        foreach ($document->getElementsByTagName('loc') as $element) {
            $locations[] = $element->textContent;
        }

        return $locations;
    }

    /**
     * @return list<string>
     */
    private function addressesInTheSitemap(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        $paths = [];

        foreach ($this->locations() as $location) {
            $paths[] = (string) parse_url($location, PHP_URL_PATH);
        }

        return $paths;
    }

    private function assertParsesAsXml(string $content): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        $parsed = $document->loadXML($content);

        $errors = libxml_get_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($parsed, 'The document is not well-formed XML.');
        self::assertSame([], $errors, 'The document parsed with errors.');

        return $document;
    }
}
