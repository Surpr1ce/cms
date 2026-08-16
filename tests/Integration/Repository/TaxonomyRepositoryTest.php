<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Tag;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\TagFactory;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class TaxonomyRepositoryTest extends KernelTestCase
{
    use Factories;

    private CategoryRepository $categories;

    private TagRepository $tags;

    private ArticleRepository $articles;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $categories = $container->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $categories);
        $this->categories = $categories;

        $tags = $container->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);
        $this->tags = $tags;

        $articles = $container->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $articles);
        $this->articles = $articles;
    }

    public function testItFindsTheChildrenOfASection(): void
    {
        $parent = CategoryFactory::createOne();
        CategoryFactory::new()->childOf($parent)->many(2)->create();
        CategoryFactory::createMany(3);

        self::assertCount(2, $this->categories->findChildrenOf($parent));
    }

    /**
     * Passing null asks for the top level, which saves every caller a special
     * case for the root of the tree.
     */
    public function testPassingNullAsksForTheTopLevel(): void
    {
        $parent = CategoryFactory::createOne();
        CategoryFactory::new()->childOf($parent)->many(2)->create();

        self::assertCount(1, $this->categories->findChildrenOf(null));
    }

    public function testTwoSectionsMayShareANameWhileKeepingDistinctAddresses(): void
    {
        CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        CategoryFactory::createOne(['name' => 'News', 'slug' => 'news-2']);

        self::assertCount(2, $this->categories->findAllOrdered());
        self::assertNotNull($this->categories->findOneBySlug('news'));
        self::assertNotNull($this->categories->findOneBySlug('news-2'));
    }

    /**
     * A tag cloud built from every row advertises drafts by name and leads
     * readers to content they cannot see, so the published scope reaches into
     * this query too.
     */
    public function testLabelsInUseAreOnlyThoseOnPublishedArticles(): void
    {
        $used = TagFactory::createOne(['slug' => 'used']);
        $onADraft = TagFactory::createOne(['slug' => 'on-a-draft']);
        $unused = TagFactory::createOne(['slug' => 'unused']);

        $published = ArticleFactory::new()->published()->create();
        $published->addTag($used);

        $draft = ArticleFactory::createOne();
        $draft->addTag($onADraft);

        $this->flush();

        $inUse = array_map(static fn (Tag $tag): string => $tag->getSlug(), $this->tags->findInUse());

        self::assertContains('used', $inUse);
        self::assertNotContains('on-a-draft', $inUse);
        self::assertNotContains($unused->getSlug(), $inUse);
    }

    public function testALabelUsedTwiceIsListedOnce(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php']);

        foreach (ArticleFactory::new()->published()->many(3)->create() as $article) {
            $article->addTag($tag);
        }

        $this->flush();

        self::assertCount(1, $this->tags->findInUse());
    }

    /**
     * FR-017: removing a label leaves the articles that carried it.
     */
    public function testDeletingALabelLeavesItsArticlesInPlace(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php']);
        $articles = ArticleFactory::createMany(3);

        foreach ($articles as $article) {
            $article->addTag($tag);
        }

        $this->flush();

        $entityManager = $this->entityManager();
        $entityManager->remove($tag);
        $entityManager->flush();

        self::assertCount(3, $this->articles->findAll());
        self::assertNull($this->tags->findOneBySlug('php'));
    }

    public function testItFindsPublishedArticlesInASection(): void
    {
        $section = CategoryFactory::createOne();
        ArticleFactory::new()->published()->many(2)->create(['category' => $section]);
        ArticleFactory::createMany(3, ['category' => $section]);
        ArticleFactory::new()->published()->many(1)->create();

        self::assertCount(2, $this->articles->findPublishedByCategory($section));
    }

    public function testItFindsPublishedArticlesCarryingALabel(): void
    {
        $tag = TagFactory::createOne();

        foreach (ArticleFactory::new()->published()->many(2)->create() as $article) {
            $article->addTag($tag);
        }

        $draft = ArticleFactory::createOne();
        $draft->addTag($tag);

        $this->flush();

        self::assertCount(2, $this->articles->findPublishedByTag($tag));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function flush(): void
    {
        $this->entityManager()->flush();
    }
}
