<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class UserRepositoryTest extends KernelTestCase
{
    use Factories;

    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $this->repository = $repository;
    }

    public function testItFindsAnAccountByItsEmailAddress(): void
    {
        UserFactory::createOne(['email' => 'editor@example.com']);

        $found = $this->repository->findOneByEmail('editor@example.com');

        self::assertInstanceOf(User::class, $found);
        self::assertSame('editor@example.com', $found->getEmail());
    }

    public function testItReturnsNullForAnUnknownEmailAddress(): void
    {
        UserFactory::createOne(['email' => 'editor@example.com']);

        self::assertNull($this->repository->findOneByEmail('nobody@example.com'));
    }

    public function testTheLookupIsCaseSensitiveAsPostgresqlStoresIt(): void
    {
        UserFactory::createOne(['email' => 'editor@example.com']);

        self::assertNull(
            $this->repository->findOneByEmail('EDITOR@example.com'),
            'Recorded rather than asserted as desirable: addresses are stored and matched verbatim. '
            .'Normalising them to lower case is a decision for the sign-in feature, not this one.',
        );
    }

    /**
     * FR-025. The refusal comes from the unique index, which is what makes it
     * hold even for a caller that never validated anything.
     */
    public function testASecondAccountCannotTakeAnExistingEmailAddress(): void
    {
        UserFactory::createOne(['email' => 'editor@example.com']);

        $duplicate = new User('editor@example.com', 'Impostor', new DateTimeImmutable());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->persist($duplicate);

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    public function testAPersistedAccountReceivesAnIdentifier(): void
    {
        $user = UserFactory::createOne();

        self::assertNotNull($user->getId());
    }

    public function testRolesSurviveTheRoundTripThroughTheDatabase(): void
    {
        $user = UserFactory::new()->admin()->create();

        $reloaded = $this->repository->findOneByEmail($user->getEmail());

        self::assertInstanceOf(User::class, $reloaded);
        self::assertContains(User::ROLE_ADMIN, $reloaded->getRoles());
        self::assertContains('ROLE_USER', $reloaded->getRoles());
    }
}
