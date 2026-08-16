<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Exception\UserStillOwnsContent;
use App\Factory\ArticleFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use App\Repository\MediaRepository;
use App\Repository\UserRepository;
use App\Service\Account\UserDeleter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * FR-028: an account that still owns content cannot be deleted.
 *
 * The case worth writing down is the archived one. Archiving content is not a
 * release of ownership, and a reading that treated it as one would let an
 * account be removed while its articles still name it as the author.
 */
final class UserDeleterTest extends KernelTestCase
{
    use Factories;

    private UserDeleter $deleter;

    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $media = self::getContainer()->get(MediaRepository::class);
        self::assertInstanceOf(MediaRepository::class, $media);

        $this->deleter = new UserDeleter($this->entityManager(), $this->articles(), $media);
    }

    public function testAnAccountOwningNothingIsDeleted(): void
    {
        UserFactory::createOne(['email' => 'nobody@example.com']);

        $user = $this->users->findOneByEmail('nobody@example.com');
        self::assertNotNull($user);

        $this->deleter->delete($user);

        self::assertNull($this->users->findOneByEmail('nobody@example.com'));
    }

    public function testAnAuthorOfArticlesIsRefused(): void
    {
        $author = UserFactory::createOne();
        ArticleFactory::createOne(['author' => $author]);

        $this->expectException(UserStillOwnsContent::class);

        $this->deleter->delete($author);
    }

    public function testAnUploaderOfFilesIsRefused(): void
    {
        $uploader = UserFactory::createOne();
        MediaFactory::createOne(['uploadedBy' => $uploader]);

        $this->expectException(UserStillOwnsContent::class);

        $this->deleter->delete($uploader);
    }

    /**
     * The spec's edge case, stated in full: archiving is not a release of
     * ownership. An archived article still names its author.
     */
    public function testAnAuthorWhoseArticlesAreAllArchivedIsStillRefused(): void
    {
        $author = UserFactory::createOne();
        ArticleFactory::new()->publishedThenArchived()->many(3)->create(['author' => $author]);

        $this->expectException(UserStillOwnsContent::class);

        $this->deleter->delete($author);
    }

    public function testAnAuthorOfOnlyDraftsIsStillRefused(): void
    {
        $author = UserFactory::createOne();
        ArticleFactory::createMany(2, ['author' => $author]);

        $this->expectException(UserStillOwnsContent::class);

        $this->deleter->delete($author);
    }

    public function testARefusedDeletionLeavesBothTheAccountAndItsContent(): void
    {
        $author = UserFactory::createOne(['email' => 'author@example.com']);
        ArticleFactory::createMany(2, ['author' => $author]);

        try {
            $this->deleter->delete($author);
        } catch (UserStillOwnsContent) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertNotNull($this->users->findOneByEmail('author@example.com'));
        self::assertSame(2, $this->articles()->countByAuthor($author));
    }

    public function testTheRefusalSaysHowMuchIsOwned(): void
    {
        $author = UserFactory::createOne(['email' => 'busy@example.com']);
        ArticleFactory::createMany(4, ['author' => $author]);
        MediaFactory::createMany(2, ['uploadedBy' => $author]);

        try {
            $this->deleter->delete($author);
            self::fail('Deleting an account that owns content should have been refused.');
        } catch (UserStillOwnsContent $userStillOwnsContent) {
            self::assertSame('busy@example.com', $userStillOwnsContent->email());
            self::assertSame(4, $userStillOwnsContent->articleCount());
            self::assertSame(2, $userStillOwnsContent->mediaCount());
        }
    }

    /**
     * Pages have no author, so they cannot block anything. This is why Page is a
     * separate entity rather than an article with a type flag.
     */
    public function testPagesDoNotBlockDeletionBecauseTheyHaveNoAuthor(): void
    {
        UserFactory::createOne(['email' => 'nobody@example.com']);
        PageFactory::createMany(3);

        $user = $this->users->findOneByEmail('nobody@example.com');
        self::assertNotNull($user);

        $this->deleter->delete($user);

        self::assertNull($this->users->findOneByEmail('nobody@example.com'));
    }

    public function testAnAccountBecomesDeletableOnceItsContentIsGone(): void
    {
        $author = UserFactory::createOne(['email' => 'author@example.com']);
        $article = ArticleFactory::createOne(['author' => $author]);

        $entityManager = $this->entityManager();
        $entityManager->remove($article);
        $entityManager->flush();

        $this->deleter->delete($author);

        self::assertNull($this->users->findOneByEmail('author@example.com'));
    }

    public function testItReportsWhetherAnAccountCanBeDeletedWithoutTrying(): void
    {
        $owner = UserFactory::createOne();
        ArticleFactory::createOne(['author' => $owner]);
        $free = UserFactory::createOne();

        self::assertFalse($this->deleter->canBeDeleted($owner));
        self::assertTrue($this->deleter->canBeDeleted($free));
    }

    /**
     * The constraint, not the service. A caller that bypasses UserDeleter still
     * cannot orphan an article.
     */
    public function testTheDatabaseRefusesItTooForACallerThatSkipsTheService(): void
    {
        $author = UserFactory::createOne();
        ArticleFactory::createOne(['author' => $author]);

        $entityManager = $this->entityManager();
        $entityManager->remove($author);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $entityManager->flush();
    }

    private function articles(): ArticleRepository
    {
        $articles = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $articles);

        return $articles;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
