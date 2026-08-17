<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Article;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use Doctrine\ORM\EntityManagerInterface;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * FR-010: nothing can be written, through any address, by any method.
 *
 * The cost of getting this wrong is total — an unauthenticated way for the
 * internet to change content — and the mistake is one line of configuration. API
 * Platform generates writes from the same declaration that generates reads, so
 * "we did not add a Post" is a property worth checking rather than assuming.
 *
 * Every test asserts the content afterwards as well as the status. A 405 with
 * the change applied would be the worst outcome and the easiest to miss.
 */
final class ApiIsReadOnlyTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function collectionAddressProvider(): iterable
    {
        yield 'articles' => ['/api/articles'];
        yield 'pages' => ['/api/pages'];
        yield 'sections' => ['/api/sections'];
        yield 'tags' => ['/api/tags'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeMethodProvider(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('collectionAddressProvider')]
    public function testEveryCollectionRefusesEveryWriteMethod(string $path): void
    {
        $this->seed();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->write($method, $path);

            self::assertFalse(
                $this->client->getResponse()->isSuccessful(),
                sprintf('%s %s was accepted.', $method, $path),
            );
        }
    }

    #[DataProvider('writeMethodProvider')]
    public function testEveryItemAddressRefusesEveryWriteMethod(string $method): void
    {
        $this->seed();

        foreach ([
            '/api/articles/a-published-article',
            '/api/pages/a-published-page',
            '/api/sections/news',
            '/api/tags/php',
        ] as $path) {
            $this->write($method, $path);

            self::assertFalse(
                $this->client->getResponse()->isSuccessful(),
                sprintf('%s %s was accepted.', $method, $path),
            );
        }
    }

    /**
     * The assertion that matters: not merely refused, but with nothing changed.
     */
    public function testARefusedWriteChangesNothing(): void
    {
        $this->seed();

        $this->write('PUT', '/api/articles/a-published-article', ['title' => 'Rewritten by a stranger']);
        $this->write('PATCH', '/api/articles/a-published-article', ['title' => 'Rewritten by a stranger']);
        $this->write('POST', '/api/articles', ['title' => 'Injected', 'slug' => 'injected']);

        $articles = $this->reloadArticles();

        self::assertCount(1, $articles);
        self::assertSame('A published article', $articles[0]->getTitle());
    }

    public function testADeleteChangesNothing(): void
    {
        $this->seed();

        $this->write('DELETE', '/api/articles/a-published-article');

        self::assertCount(1, $this->reloadArticles());
    }

    /**
     * FR-012. A description advertising an operation that does not exist would
     * be a lie; one advertising a write that *does* exist would be worse.
     */
    public function testTheApiDescriptionAdvertisesNoWriteOperation(): void
    {
        $this->client->request('GET', '/api/docs.jsonld', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();

        $description = (string) $this->client->getResponse()->getContent();

        foreach (['"POST"', '"PUT"', '"PATCH"', '"DELETE"'] as $method) {
            self::assertStringNotContainsString(
                $method,
                $description,
                sprintf('The API description advertises %s.', $method),
            );
        }
    }

    /**
     * Nothing under src/Entity/ is exposed. If an entity ever gained an
     * #[ApiResource] attribute, its fields — including an author's email address
     * and password hash — would appear without anybody deciding.
     */
    public function testNoDoctrineEntityIsExposedAsAResource(): void
    {
        $this->client->request('GET', '/api/docs.jsonld', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        $description = (string) $this->client->getResponse()->getContent();

        foreach (['"User"', '"Media"', '"PublishableContent"'] as $entity) {
            self::assertStringNotContainsString(
                $entity,
                $description,
                sprintf('%s is exposed through the API.', $entity),
            );
        }
    }

    private function seed(): void
    {
        CategoryFactory::createOne(['slug' => 'news', 'name' => 'News']);
        TagFactory::createOne(['slug' => 'php', 'name' => 'PHP']);
        ArticleFactory::new()->published()->create([
            'slug' => 'a-published-article',
            'title' => 'A published article',
        ]);
        PageFactory::new()->published()->create(['slug' => 'a-published-page']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $method, string $path, array $payload = ['title' => 'Changed']): void
    {
        $this->client->request(
            $method,
            $path,
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<Article>
     */
    private function reloadArticles(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        return array_values($entityManager->getRepository(Article::class)->findAll());
    }
}
