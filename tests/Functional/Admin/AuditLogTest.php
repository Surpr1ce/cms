<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditAction;
use App\Entity\AuditEntry;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\MediaFactory;
use App\Factory\UserFactory;
use App\Repository\AuditEntryRepository;

use function array_map;

use Doctrine\ORM\EntityManagerInterface;

use function sprintf;
use function str_contains;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * A record of who did what.
 *
 * Two properties get most of the attention here, and both are about the log
 * being useful at the moment somebody actually reaches for it — which is always
 * after something has gone.
 *
 * **An entry outlives its subject.** Deleting an article must leave behind an
 * entry that still names it. A log that stores a reference to a deleted row says
 * nothing at all.
 *
 * **An entry outlives its author.** Deleting an account must leave its history
 * readable and still attributed. This is the one that a naive foreign key breaks
 * silently — either by cascading the entries away or by rendering a blank name.
 */
final class AuditLogTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // --------------------------------------------------- what gets recorded

    public function testPublishingIsRecorded(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne([
            'title' => 'A distinctive headline',
            'content' => '<p>Long enough to publish.</p>',
        ]);

        $this->transition($article->getId(), 'publish');

        self::assertSame(
            [[AuditAction::ContentPublished, 'A distinctive headline']],
            $this->recorded(),
        );
    }

    public function testEveryTransitionIsRecorded(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne(['content' => '<p>Long enough to publish.</p>']);

        foreach (['publish', 'unpublish', 'publish', 'archive', 'restore'] as $transition) {
            $this->transition($article->getId(), $transition);
        }

        self::assertSame(
            [
                AuditAction::ContentPublished,
                AuditAction::ContentUnpublished,
                AuditAction::ContentPublished,
                AuditAction::ContentArchived,
                AuditAction::ContentRestored,
            ],
            array_map(static fn (array $entry): AuditAction => $entry[0], $this->recorded()),
        );
    }

    /**
     * FR-006. A publication that was refused did not happen.
     */
    public function testARefusedTransitionIsNotRecorded(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        // No body, so publishing is refused by the entity.
        $article = ArticleFactory::createOne(['content' => '']);

        $this->transition($article->getId(), 'publish');

        self::assertSame([], $this->recorded());
    }

    /**
     * The case the whole design is for: the article is gone, and the entry still
     * says what it was.
     */
    public function testDeletingAnArticleLeavesAnEntryThatStillNamesIt(): void
    {
        $account = $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne([
            'title' => 'The one that disappeared',
            'author' => $account,
        ]);

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Delete this article')->form());

        self::assertSame(
            [[AuditAction::ContentDeleted, 'The one that disappeared']],
            $this->recorded(),
        );
    }

    public function testDeletingAFileIsRecorded(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $media = MediaFactory::createOne(['originalName' => 'a-photograph.jpg']);

        $crawler = $this->client->request('GET', '/admin/media');
        $this->client->submit($crawler->filter('form[action$="/'.$media->getId().'/delete"]')->form());

        self::assertSame([[AuditAction::FileDeleted, 'a-photograph.jpg']], $this->recorded());
    }

    public function testAPasswordChangeIsRecordedWithoutThePassword(): void
    {
        $account = $this->signIn([User::ROLE_EDITOR], 'the-current-password');

        $crawler = $this->client->request('GET', '/admin/account');
        $this->client->submit($crawler->selectButton('Change it')->form([
            'currentPassword' => 'the-current-password',
            'password' => 'a-very-distinctive-new-password',
            'confirmation' => 'a-very-distinctive-new-password',
        ]));

        $recorded = $this->recorded();

        self::assertSame([[AuditAction::PasswordChanged, $account->getEmail()]], $recorded);

        // FR-005, from the other side: nothing anywhere in the entry.
        foreach ($this->everyEntry() as $entry) {
            self::assertStringNotContainsString('a-very-distinctive-new-password', $entry->getSubject());
        }
    }

    // ------------------------------------------------------------- reading

    public function testAnAdministratorCanReadTheLog(): void
    {
        $this->signIn([User::ROLE_ADMIN]);
        $article = ArticleFactory::createOne([
            'title' => 'A distinctive headline',
            'content' => '<p>Long enough.</p>',
        ]);
        $this->transition($article->getId(), 'publish');

        $crawler = $this->client->request('GET', '/admin/log');

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('main')->text();

        self::assertStringContainsString('A distinctive headline', $text);
        self::assertStringContainsString('published', $text);
        self::assertStringContainsString('person@example.com', $text);
    }

    /**
     * FR-009. Reading who did what is the same kind of authority as deciding who
     * may do it — an editor with this screen would have a surveillance tool
     * nobody granted them.
     */
    public function testAnybodyBelowAnAdministratorIsRefused(): void
    {
        // Distinct addresses and a sign-out between, rather than a fresh client
        // each time. Rebooting the kernel does not roll back what the previous
        // account wrote — the suite runs inside one transaction — so a second
        // `person@example.com` collides with the first.
        foreach ([User::ROLE_AUTHOR, User::ROLE_EDITOR] as $role) {
            $this->signIn([$role], email: strtolower($role).'@example.com');

            $this->client->request('GET', '/admin/log');

            self::assertResponseStatusCodeSame(403, sprintf('%s reached the log.', $role));

            $this->client->request('POST', '/logout');
        }
    }

    public function testSomebodyNotSignedInIsSentToTheSignInPage(): void
    {
        $this->client->request('GET', '/admin/log');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // --------------------------------------------------------- permanence

    /**
     * FR-013, and the property a naive foreign key breaks silently — either by
     * taking the entries with the account or by leaving them attributed to
     * nobody.
     */
    public function testDeletingAnAccountLeavesItsHistoryReadable(): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        $doomed = UserFactory::new()->editor()->create(['email' => 'departing@example.com']);

        // An entry made by the administrator about the account, and then the
        // account itself removed.
        $this->deleteAccount($doomed->getId());

        $crawler = $this->client->request('GET', '/admin/log');
        $text = $crawler->filter('main')->text();

        self::assertStringContainsString('departing@example.com', $text);
        self::assertStringContainsString('deleted the account', $text);
    }

    /**
     * FR-011 and FR-012, asserted the only way a property like this can be:
     * by looking for the routes that would have to exist.
     */
    public function testNoRouteCanChangeOrRemoveAnEntry(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_contains($route->getPath(), '/admin/log')) {
                continue;
            }

            self::assertSame(
                ['GET'],
                $route->getMethods(),
                sprintf('The route "%s" can do more than read the log.', $name),
            );
        }
    }

    // ---------------------------------------------------------- helpers

    /**
     * @return list<array{AuditAction, string}>
     */
    private function recorded(): array
    {
        return array_map(
            static fn (AuditEntry $entry): array => [$entry->getAction(), $entry->getSubject()],
            $this->everyEntry(),
        );
    }

    /**
     * @return list<AuditEntry>
     */
    private function everyEntry(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(AuditEntryRepository::class);
        self::assertInstanceOf(AuditEntryRepository::class, $repository);

        return array_values($repository->findBy([], ['id' => 'ASC']));
    }

    private function transition(?int $id, string $transition): void
    {
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', '/admin/articles/'.$id.'/edit');
        $button = $crawler->selectButton(ucfirst($transition));

        self::assertGreaterThan(0, $button->count(), 'There is no "'.$transition.'" control on the screen.');

        $this->client->submit($button->form());
    }

    private function deleteAccount(?int $id): void
    {
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', '/admin/manage/accounts/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Delete this account')->form());
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(
        array $roles,
        string $password = UserFactory::DEVELOPMENT_PASSWORD,
        string $email = 'person@example.com',
    ): User {
        $account = UserFactory::new()->withPassword()->create([
            'email' => $email,
            'roles' => $roles,
        ]);

        if (UserFactory::DEVELOPMENT_PASSWORD !== $password) {
            $hasher = self::getContainer()->get('security.user_password_hasher');
            self::assertNotNull($hasher);

            $account->setPassword($hasher->hashPassword($account, $password));

            $entityManager = self::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
            $entityManager->flush();
        }

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
        $this->client->followRedirect();

        return $account;
    }
}
