<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Slug;

use App\Entity\Slug;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Repository\ArticleRepository;
use App\Repository\PageRepository;
use App\Service\Slug\SlugGenerator;
use App\Service\Slug\UniqueSlugGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Collision handling, which is the half of address generation that needs a
 * database and therefore cannot live in the pure generator.
 */
final class UniqueSlugGeneratorTest extends KernelTestCase
{
    use Factories;

    private UniqueSlugGenerator $generator;

    private ArticleRepository $articles;

    private PageRepository $pages;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        // Constructed rather than fetched from the container: nothing consumes
        // it yet, so the compiler removes it as an unused private service. The
        // wiring becomes worth asserting when the admin layer calls it.
        $this->generator = new UniqueSlugGenerator(new SlugGenerator());

        $articles = $container->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $articles);
        $this->articles = $articles;

        $pages = $container->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);
        $this->pages = $pages;
    }

    public function testAFreeAddressIsUsedAsIs(): void
    {
        self::assertSame('hello-world', $this->generator->generate('Hello, World!', $this->articles));
    }

    public function testASecondArticleWithTheSameTitleGetsASuffix(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);

        self::assertSame('hello-world-2', $this->generator->generate('Hello, World!', $this->articles));
    }

    public function testTheSuffixCountsUpwards(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        ArticleFactory::createOne(['slug' => 'hello-world-2']);
        ArticleFactory::createOne(['slug' => 'hello-world-3']);

        self::assertSame('hello-world-4', $this->generator->generate('Hello, World!', $this->articles));
    }

    /**
     * A gap in the sequence is filled rather than skipped past. The suffix is a
     * collision resolver, not a counter of how many articles ever shared a
     * title.
     */
    public function testAGapInTheSequenceIsFilled(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        ArticleFactory::createOne(['slug' => 'hello-world-3']);

        self::assertSame('hello-world-2', $this->generator->generate('Hello, World!', $this->articles));
    }

    /**
     * FR-010 and US2 scenario 4: addresses are unique per kind, so an article
     * and a page may share one. This is the reason the repository is a per-call
     * argument rather than a constructor dependency.
     */
    public function testAPageIsUnaffectedByAnArticleWithTheSameAddress(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);

        self::assertSame('hello-world', $this->generator->generate('Hello, World!', $this->pages));
    }

    public function testAnArticleIsUnaffectedByAPageWithTheSameAddress(): void
    {
        PageFactory::createOne(['slug' => 'hello-world']);

        self::assertSame('hello-world', $this->generator->generate('Hello, World!', $this->articles));
    }

    public function testCollisionsAreResolvedIndependentlyPerKind(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        PageFactory::createOne(['slug' => 'hello-world']);

        self::assertSame('hello-world-2', $this->generator->generate('Hello, World!', $this->articles));
        self::assertSame('hello-world-2', $this->generator->generate('Hello, World!', $this->pages));
    }

    /**
     * A title that reduces to nothing still produces something usable, and the
     * result is still a legal address.
     */
    public function testAnUnusableTitleStillProducesAUsableAddress(): void
    {
        $slug = $this->generator->generate('!!!', $this->articles);

        self::assertMatchesRegularExpression(Slug::PATTERN, $slug);
    }

    /**
     * Whatever it returns must be acceptable to the entity, or the two halves of
     * FR-009 would disagree and only the database would notice.
     */
    public function testEveryGeneratedAddressIsAcceptedByTheEntity(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);

        foreach (['Hello, World!', 'Ĺ˝ltĂ˝ kĂ´Ĺ', 'Symfony 8.1 release', 'ć—Ąćś¬čŞž'] as $title) {
            self::assertMatchesRegularExpression(
                Slug::PATTERN,
                $this->generator->generate($title, $this->articles),
            );
        }
    }

    /**
     * The generated address is free at the moment it is handed back â€” which is
     * the whole promise, and all a single process can promise.
     */
    public function testTheResultIsNotTakenAtTheMomentItIsReturned(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        ArticleFactory::createOne(['slug' => 'hello-world-2']);

        $slug = $this->generator->generate('Hello, World!', $this->articles);

        self::assertFalse($this->articles->existsWithSlug($slug));
    }
}
