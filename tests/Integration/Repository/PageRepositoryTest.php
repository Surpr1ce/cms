<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Page;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The same published-scope assertions as for articles, which is US4 scenario 5
 * — "a page behaves exactly as an article does" — proven at the repository level
 * rather than assumed from the shared superclass.
 */
final class PageRepositoryTest extends KernelTestCase
{
    use Factories;

    private PageRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $repository);

        $this->repository = $repository;
    }

    public function testADraftIsReachableBySlugButNotAsPublishedContent(): void
    {
        PageFactory::createOne(['slug' => 'a-draft-page']);

        self::assertNotNull($this->repository->findOneBySlug('a-draft-page'));
        self::assertNull($this->repository->findOnePublishedBySlug('a-draft-page'));
    }

    public function testPublishedContentIsReachableBothWays(): void
    {
        PageFactory::new()->published()->create(['slug' => 'about-us']);

        self::assertInstanceOf(Page::class, $this->repository->findOneBySlug('about-us'));
        self::assertInstanceOf(Page::class, $this->repository->findOnePublishedBySlug('about-us'));
    }

    public function testArchivedContentIsNotReachableAsPublishedContent(): void
    {
        PageFactory::new()->publishedThenArchived()->create(['slug' => 'retired']);

        self::assertNull($this->repository->findOnePublishedBySlug('retired'));
    }

    public function testTheListingExcludesDraftsAndArchivedContent(): void
    {
        PageFactory::createMany(2);
        PageFactory::new()->archived()->many(1)->create();
        PageFactory::new()->published()->many(3)->create();

        $listed = $this->repository->findPublished();

        self::assertCount(3, $listed);

        foreach ($listed as $page) {
            self::assertTrue($page->isPublished());
        }
    }

    public function testItCountsOnlyPublishedPages(): void
    {
        PageFactory::createMany(2);
        PageFactory::new()->published()->many(4)->create();

        self::assertSame(4, $this->repository->countPublished());
    }

    /**
     * FR-010: addresses are unique per kind, so the same one may appear once in
     * each table. Both remain retrievable, which is what makes the rule useful
     * rather than merely permitted.
     */
    public function testAPageMayShareAnAddressWithAnArticle(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        PageFactory::createOne(['slug' => 'hello-world']);

        self::assertInstanceOf(Page::class, $this->repository->findOneBySlug('hello-world'));
    }

    public function testItReportsWhetherASlugIsTaken(): void
    {
        PageFactory::createOne(['slug' => 'taken-page']);

        self::assertTrue($this->repository->existsWithSlug('taken-page'));
        self::assertFalse($this->repository->existsWithSlug('free-page'));
    }
}
