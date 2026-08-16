<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Content;

use App\Entity\Page;
use App\Exception\PageStillHasChildren;
use App\Factory\PageFactory;
use App\Repository\PageRepository;
use App\Service\Content\PageDeleter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * FR-018: a page with pages below it cannot be deleted.
 *
 * The rule is enforced twice, and both are asserted here — the service, which
 * produces an error somebody can act on, and the ON DELETE RESTRICT constraint,
 * which holds for a caller that never heard of the service.
 */
final class PageDeleterTest extends KernelTestCase
{
    use Factories;

    private PageDeleter $deleter;

    private PageRepository $pages;

    protected function setUp(): void
    {
        self::bootKernel();

        $pages = self::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);
        $this->pages = $pages;

        $this->deleter = new PageDeleter($this->entityManager(), $pages);
    }

    public function testALeafPageIsDeleted(): void
    {
        PageFactory::createOne(['slug' => 'contact']);

        $page = $this->pages->findOneBySlug('contact');
        self::assertInstanceOf(Page::class, $page);

        $this->deleter->delete($page);

        self::assertNull($this->pages->findOneBySlug('contact'));
    }

    public function testAPageWithChildrenIsRefused(): void
    {
        $parent = PageFactory::createOne(['slug' => 'about-us']);
        PageFactory::new()->childOf($parent)->create(['slug' => 'our-team']);

        $this->expectException(PageStillHasChildren::class);

        $this->deleter->delete($parent);
    }

    public function testARefusedDeletionRemovesNothing(): void
    {
        $parent = PageFactory::createOne(['slug' => 'about-us']);
        PageFactory::new()->childOf($parent)->create(['slug' => 'our-team']);

        try {
            $this->deleter->delete($parent);
        } catch (PageStillHasChildren) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertNotNull($this->pages->findOneBySlug('about-us'));
        self::assertNotNull($this->pages->findOneBySlug('our-team'));
    }

    public function testTheRefusalSaysHowManyChildrenAreInTheWay(): void
    {
        $parent = PageFactory::createOne(['slug' => 'about-us']);
        PageFactory::new()->childOf($parent)->many(3)->create();

        try {
            $this->deleter->delete($parent);
            self::fail('Deleting a page with children should have been refused.');
        } catch (PageStillHasChildren $pageStillHasChildren) {
            self::assertSame(3, $pageStillHasChildren->childCount());
            self::assertSame($parent->getTitle(), $pageStillHasChildren->pageTitle());
        }
    }

    public function testAPageIsDeletableOnceItsChildrenHaveMovedAway(): void
    {
        $parent = PageFactory::createOne(['slug' => 'about-us']);
        $child = PageFactory::new()->childOf($parent)->create(['slug' => 'our-team']);

        $child->setParent(null);
        $this->entityManager()->flush();

        $this->deleter->delete($parent);

        self::assertNull($this->pages->findOneBySlug('about-us'));
        self::assertNotNull($this->pages->findOneBySlug('our-team'));
    }

    /**
     * The constraint, not the service. A caller that bypasses PageDeleter still
     * cannot orphan a page — it gets a foreign-key violation instead of a
     * readable message, which is the trade for the guarantee holding everywhere.
     */
    public function testTheDatabaseRefusesItTooForACallerThatSkipsTheService(): void
    {
        $parent = PageFactory::createOne(['slug' => 'about-us']);
        PageFactory::new()->childOf($parent)->create(['slug' => 'our-team']);

        $entityManager = $this->entityManager();
        $entityManager->remove($parent);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $entityManager->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
