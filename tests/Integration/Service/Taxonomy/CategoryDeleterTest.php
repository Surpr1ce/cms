<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Taxonomy;

use App\Entity\Category;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Service\Taxonomy\CategoryDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * FR-016: deleting a grouping never destroys what it grouped.
 *
 * Both halves are asserted — the articles that survive, and the child sections
 * that move up rather than being orphaned or removed.
 */
final class CategoryDeleterTest extends KernelTestCase
{
    use Factories;

    private CategoryDeleter $deleter;

    private CategoryRepository $categories;

    private ArticleRepository $articles;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $categories = $container->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $categories);
        $this->categories = $categories;

        $articles = $container->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $articles);
        $this->articles = $articles;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->deleter = new CategoryDeleter($entityManager, $articles);
    }

    public function testTheSectionItselfIsRemoved(): void
    {
        $category = CategoryFactory::createOne(['slug' => 'news']);

        $this->deleter->delete($category);

        self::assertNull($this->categories->findOneBySlug('news'));
    }

    /**
     * US3 scenario 3.
     */
    public function testItsArticlesSurviveAndBecomeUnsectioned(): void
    {
        $category = CategoryFactory::createOne();
        ArticleFactory::createMany(3, ['category' => $category]);

        $this->deleter->delete($category);

        $survivors = $this->articles->findAll();

        self::assertCount(3, $survivors);

        foreach ($survivors as $article) {
            self::assertNull($article->getCategory());
        }
    }

    /**
     * The in-memory clearing matters as much as the constraint: an Article
     * already loaded would otherwise keep pointing at a row that is gone.
     */
    public function testAnAlreadyLoadedArticleNoLongerPointsAtTheDeletedSection(): void
    {
        $category = CategoryFactory::createOne();
        $article = ArticleFactory::createOne(['category' => $category]);

        self::assertNotNull($article->getCategory());

        $this->deleter->delete($category);

        self::assertNull($article->getCategory());
    }

    /**
     * US3 scenario 4: children move up to their grandparent.
     */
    public function testItsChildrenAreReattachedToItsFormerParent(): void
    {
        $grandparent = CategoryFactory::createOne(['slug' => 'root']);
        $parent = CategoryFactory::new()->childOf($grandparent)->create(['slug' => 'middle']);
        CategoryFactory::new()->childOf($parent)->create(['slug' => 'leaf-one']);
        CategoryFactory::new()->childOf($parent)->create(['slug' => 'leaf-two']);

        $this->deleter->delete($parent);

        foreach (['leaf-one', 'leaf-two'] as $slug) {
            $child = $this->categories->findOneBySlug($slug);
            self::assertInstanceOf(Category::class, $child);
            self::assertSame('root', $child->getParent()?->getSlug());
        }
    }

    public function testChildrenOfATopLevelSectionBecomeTopLevelThemselves(): void
    {
        $parent = CategoryFactory::createOne(['slug' => 'top']);
        CategoryFactory::new()->childOf($parent)->create(['slug' => 'below']);

        $this->deleter->delete($parent);

        $child = $this->categories->findOneBySlug('below');
        self::assertInstanceOf(Category::class, $child);
        self::assertNull($child->getParent());
    }

    public function testNoChildIsRemovedAlongWithItsParent(): void
    {
        $parent = CategoryFactory::createOne();
        CategoryFactory::new()->childOf($parent)->many(3)->create();

        $this->deleter->delete($parent);

        self::assertCount(3, $this->categories->findAll());
    }

    public function testArticlesInOtherSectionsAreUntouched(): void
    {
        $doomed = CategoryFactory::createOne();
        $survivor = CategoryFactory::createOne(['slug' => 'survivor']);
        ArticleFactory::createOne(['category' => $doomed]);
        ArticleFactory::createMany(2, ['category' => $survivor]);

        $this->deleter->delete($doomed);

        self::assertCount(2, $this->articles->findByCategory($survivor));
    }
}
