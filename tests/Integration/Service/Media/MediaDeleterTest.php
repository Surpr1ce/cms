<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Media;

use App\Entity\Article;
use App\Entity\Page;
use App\Factory\ArticleFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Repository\ArticleRepository;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use App\Service\Media\DerivedImages;
use App\Service\Media\MediaDeleter;
use App\Service\Media\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * FR-024: deleting a catalogued file leaves the content that used it intact.
 *
 * The interesting assertion is the one about the already-loaded entity. The
 * database clears the column by itself; Doctrine's identity map does not, and a
 * stale reference would surface a long way from this line.
 */
final class MediaDeleterTest extends KernelTestCase
{
    use Factories;

    private MediaDeleter $deleter;

    private MediaRepository $media;

    private ArticleRepository $articles;

    private PageRepository $pages;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $media = $container->get(MediaRepository::class);
        self::assertInstanceOf(MediaRepository::class, $media);
        $this->media = $media;

        $articles = $container->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $articles);
        $this->articles = $articles;

        $pages = $container->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);
        $this->pages = $pages;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // Feature 005 gave the deleter storage to clear as well as a row. These
        // records have no bytes behind them — MediaStorage::remove() is content
        // with that, which is itself the behaviour a record outliving its file
        // depends on.
        $storage = $container->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        $derived = $container->get(DerivedImages::class);
        self::assertInstanceOf(DerivedImages::class, $derived);

        $this->deleter = new MediaDeleter($entityManager, $articles, $pages, $storage, $derived);
    }

    public function testTheFileRecordIsRemoved(): void
    {
        $media = MediaFactory::createOne();
        $filename = $media->getFilename();

        $this->deleter->delete($media);

        self::assertNull($this->media->findOneByFilename($filename));
    }

    public function testAnArticleUsingItSurvivesWithoutALeadImage(): void
    {
        $media = MediaFactory::createOne();
        ArticleFactory::createOne(['slug' => 'illustrated', 'featuredImage' => $media]);

        $this->deleter->delete($media);

        $article = $this->articles->findOneBySlug('illustrated');
        self::assertInstanceOf(Article::class, $article);
        self::assertNull($article->getFeaturedImage());
    }

    public function testAPageUsingItSurvivesWithoutALeadImage(): void
    {
        $media = MediaFactory::createOne();
        PageFactory::createOne(['slug' => 'illustrated-page', 'featuredImage' => $media]);

        $this->deleter->delete($media);

        $page = $this->pages->findOneBySlug('illustrated-page');
        self::assertInstanceOf(Page::class, $page);
        self::assertNull($page->getFeaturedImage());
    }

    /**
     * The reason the service does by hand what ON DELETE SET NULL already does
     * in the table.
     */
    public function testAnAlreadyLoadedArticleNoLongerPointsAtTheDeletedFile(): void
    {
        $media = MediaFactory::createOne();
        $article = ArticleFactory::createOne(['featuredImage' => $media]);

        self::assertNotNull($article->getFeaturedImage());

        $this->deleter->delete($media);

        self::assertNull($article->getFeaturedImage());
    }

    public function testEveryPieceOfContentUsingItIsDetached(): void
    {
        $media = MediaFactory::createOne();
        ArticleFactory::createMany(3, ['featuredImage' => $media]);
        PageFactory::createMany(2, ['featuredImage' => $media]);

        $this->deleter->delete($media);

        self::assertCount(3, $this->articles->findAll());
        self::assertCount(2, $this->pages->findAll());

        foreach ($this->articles->findAll() as $article) {
            self::assertNull($article->getFeaturedImage());
        }
    }

    public function testContentUsingOtherFilesIsUntouched(): void
    {
        $doomed = MediaFactory::createOne();
        $survivor = MediaFactory::createOne();

        ArticleFactory::createOne(['featuredImage' => $doomed]);
        ArticleFactory::createOne(['slug' => 'other', 'featuredImage' => $survivor]);

        $this->deleter->delete($doomed);

        $other = $this->articles->findOneBySlug('other');
        self::assertInstanceOf(Article::class, $other);
        self::assertNotNull($other->getFeaturedImage());
    }

    public function testItCountsWhatAnAccountUploaded(): void
    {
        $uploader = MediaFactory::createOne()->getUploadedBy();
        MediaFactory::createMany(2, ['uploadedBy' => $uploader]);
        MediaFactory::createMany(3);

        self::assertSame(3, $this->media->countUploadedBy($uploader));
    }
}
