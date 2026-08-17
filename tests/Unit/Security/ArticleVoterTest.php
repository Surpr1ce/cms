<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Article;
use App\Entity\User;
use App\Security\ArticleVoter;

use function array_key_exists;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function sprintf;

use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The permission matrix, asserted rather than sampled.
 *
 * The refusals matter more than the grants here. A voter that returns true for
 * everything passes every happy-path test anybody has ever written, and the
 * failure is silent and total — nothing breaks, everybody can do everything.
 * So every combination of role, ownership and state is enumerated, and the
 * `false` rows outnumber the `true` ones.
 */
final class ArticleVoterTest extends TestCase
{
    private const int OWNER_ID = 1;

    private const int OTHER_ID = 2;

    /**
     * Role, whether the article is theirs, its state, the permission, the answer.
     *
     * @return iterable<string, array{list<string>, bool, string, string, bool}>
     */
    public static function matrixProvider(): iterable
    {
        $author = [User::ROLE_AUTHOR];
        $editor = [User::ROLE_EDITOR];
        $admin = [User::ROLE_ADMIN];

        // --- an author, their own draft ---
        yield 'author edits own draft' => [$author, true, 'draft', ArticleVoter::EDIT, true];
        yield 'author deletes own draft' => [$author, true, 'draft', ArticleVoter::DELETE, true];
        yield 'author views own draft' => [$author, true, 'draft', ArticleVoter::VIEW, true];
        yield 'author cannot publish own draft' => [$author, true, 'draft', ArticleVoter::PUBLISH, false];

        // --- an author, somebody else's ---
        yield 'author cannot edit another draft' => [$author, false, 'draft', ArticleVoter::EDIT, false];
        yield 'author cannot delete another draft' => [$author, false, 'draft', ArticleVoter::DELETE, false];
        yield 'author cannot view another draft' => [$author, false, 'draft', ArticleVoter::VIEW, false];
        yield 'author cannot publish another draft' => [$author, false, 'draft', ArticleVoter::PUBLISH, false];
        yield 'author cannot edit another published' => [$author, false, 'published', ArticleVoter::EDIT, false];
        yield 'author cannot edit another archived' => [$author, false, 'archived', ArticleVoter::EDIT, false];

        // --- an author, their own once it is out ---
        yield 'author cannot edit own published' => [$author, true, 'published', ArticleVoter::EDIT, false];
        yield 'author cannot delete own published' => [$author, true, 'published', ArticleVoter::DELETE, false];
        yield 'author cannot edit own archived' => [$author, true, 'archived', ArticleVoter::EDIT, false];
        yield 'author cannot edit own unpublished-again' => [$author, true, 'unpublished', ArticleVoter::EDIT, false];
        yield 'author may still view own published' => [$author, true, 'published', ArticleVoter::VIEW, true];

        // --- an editor, whoever wrote it ---
        yield 'editor edits another draft' => [$editor, false, 'draft', ArticleVoter::EDIT, true];
        yield 'editor deletes another draft' => [$editor, false, 'draft', ArticleVoter::DELETE, true];
        yield 'editor publishes another draft' => [$editor, false, 'draft', ArticleVoter::PUBLISH, true];
        yield 'editor views another draft' => [$editor, false, 'draft', ArticleVoter::VIEW, true];
        yield 'editor edits another published' => [$editor, false, 'published', ArticleVoter::EDIT, true];
        yield 'editor edits another archived' => [$editor, false, 'archived', ArticleVoter::EDIT, true];

        // --- an administrator holds everything an editor holds, by explicit
        //     grant rather than by hierarchy. These rows are what would notice
        //     if a future permission named only ROLE_EDITOR.
        yield 'admin edits another draft' => [$admin, false, 'draft', ArticleVoter::EDIT, true];
        yield 'admin deletes another draft' => [$admin, false, 'draft', ArticleVoter::DELETE, true];
        yield 'admin publishes another draft' => [$admin, false, 'draft', ArticleVoter::PUBLISH, true];
        yield 'admin views another draft' => [$admin, false, 'draft', ArticleVoter::VIEW, true];

        // --- an account with nothing ---
        yield 'no roles cannot edit own draft' => [[], true, 'draft', ArticleVoter::EDIT, false];
        yield 'no roles cannot view another draft' => [[], false, 'draft', ArticleVoter::VIEW, false];
        yield 'no roles cannot publish' => [[], true, 'draft', ArticleVoter::PUBLISH, false];

        // --- an unrecognised role grants nothing ---
        yield 'invented role cannot edit' => [['ROLE_SUPERUSER'], false, 'draft', ArticleVoter::EDIT, false];
        yield 'invented role cannot publish' => [['ROLE_SUPERUSER'], true, 'draft', ArticleVoter::PUBLISH, false];

        // --- published work is readable by anybody signed in ---
        yield 'author views another published' => [$author, false, 'published', ArticleVoter::VIEW, true];
        yield 'no roles views published' => [[], false, 'published', ArticleVoter::VIEW, true];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('matrixProvider')]
    public function testThePermissionMatrix(
        array $roles,
        bool $ownsIt,
        string $state,
        string $permission,
        bool $expected,
    ): void {
        $actor = $this->account(self::OWNER_ID, $roles);
        $owner = $ownsIt ? $actor : $this->account(self::OTHER_ID, [User::ROLE_AUTHOR]);

        $decision = new ArticleVoter()->vote(
            $this->tokenFor($actor),
            $this->article($owner, $state),
            [$permission],
        );

        self::assertSame(
            $expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $decision,
        );
    }

    public function testNobodySignedInIsGrantedNothing(): void
    {
        $article = $this->article($this->account(self::OWNER_ID, [User::ROLE_ADMIN]), 'draft');

        foreach ([ArticleVoter::VIEW, ArticleVoter::EDIT, ArticleVoter::DELETE, ArticleVoter::PUBLISH] as $permission) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                new ArticleVoter()->vote($this->anonymousToken(), $article, [$permission]),
                sprintf('%s was granted to nobody.', $permission),
            );
        }
    }

    /**
     * FR-023: a question about something that is not an article is abstained
     * from rather than answered, so no other voter's answer is overridden and
     * nothing is granted by accident.
     */
    public function testAQuestionAboutSomethingElseIsAbstainedFrom(): void
    {
        $token = $this->tokenFor($this->account(self::OWNER_ID, [User::ROLE_ADMIN]));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new ArticleVoter()->vote($token, null, [ArticleVoter::EDIT]),
        );

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new ArticleVoter()->vote($token, new stdClass(), [ArticleVoter::EDIT]),
        );
    }

    public function testAnUnknownPermissionIsAbstainedFrom(): void
    {
        $actor = $this->account(self::OWNER_ID, [User::ROLE_ADMIN]);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new ArticleVoter()->vote(
                $this->tokenFor($actor),
                $this->article($actor, 'draft'),
                ['ARTICLE_INVENTED'],
            ),
        );
    }

    /**
     * Two unpersisted accounts both have a null identifier. Comparing those as
     * equal would make every unsaved article belong to everybody.
     */
    public function testTwoUnsavedAccountsAreNotTreatedAsTheSamePerson(): void
    {
        $actor = $this->account(null, [User::ROLE_AUTHOR]);
        $somebodyElse = $this->account(null, [User::ROLE_AUTHOR]);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            new ArticleVoter()->vote(
                $this->tokenFor($actor),
                $this->article($somebodyElse, 'draft'),
                [ArticleVoter::EDIT],
            ),
        );
    }

    public function testAnUnsavedAccountStillOwnsItsOwnUnsavedArticle(): void
    {
        $actor = $this->account(null, [User::ROLE_AUTHOR]);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new ArticleVoter()->vote(
                $this->tokenFor($actor),
                $this->article($actor, 'draft'),
                [ArticleVoter::EDIT],
            ),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function account(?int $id, array $roles): User
    {
        $user = new User(
            sprintf('person-%s@example.com', $id ?? 'unsaved-'.spl_object_id(new stdClass())),
            'A Person',
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
        $user->setRoles($roles);

        if (null !== $id) {
            $this->assignIdentifier($user, $id);
        }

        return $user;
    }

    private function article(User $owner, string $state): Article
    {
        $article = new Article('An article', 'an-article', $owner, new DateTimeImmutable('2026-04-01 09:00:00'));
        $article->setContent('Something worth reading.');

        match ($state) {
            'draft' => null,
            'published' => $article->publish(new DateTimeImmutable('2026-05-01 10:00:00')),
            'archived' => $this->publishThenArchive($article),
            // Published once and taken down again: a draft by status, but its
            // address is frozen and readers have seen it, so it is no longer
            // its author's alone.
            'unpublished' => $this->publishThenUnpublish($article),
            default => throw new InvalidArgumentException('Unknown state: '.$state),
        };

        return $article;
    }

    private function publishThenArchive(Article $article): void
    {
        $article->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        $article->archive();
    }

    private function publishThenUnpublish(Article $article): void
    {
        $article->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        $article->unpublish();
    }

    private function tokenFor(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function anonymousToken(): TokenInterface
    {
        return new class implements TokenInterface {
            /** @var array<string, mixed> */
            private array $attributes = [];

            public function __toString(): string
            {
                return '';
            }

            /** @return list<string> */
            public function getRoleNames(): array
            {
                return [];
            }

            public function getUser(): null
            {
                return null;
            }

            public function setUser(mixed $user): void
            {
            }

            public function getUserIdentifier(): string
            {
                return '';
            }

            public function eraseCredentials(): void
            {
            }

            /** @return array<string, mixed> */
            public function getAttributes(): array
            {
                return $this->attributes;
            }

            /** @param array<string, mixed> $attributes */
            public function setAttributes(array $attributes): void
            {
                $this->attributes = $attributes;
            }

            public function hasAttribute(string $name): bool
            {
                return array_key_exists($name, $this->attributes);
            }

            public function getAttribute(string $name): mixed
            {
                return $this->attributes[$name] ?? null;
            }

            public function setAttribute(string $name, mixed $value): void
            {
                $this->attributes[$name] = $value;
            }

            /** @return array<string, mixed> */
            public function __serialize(): array
            {
                return $this->attributes;
            }

            /** @param array<string, mixed> $data */
            public function __unserialize(array $data): void
            {
                $this->attributes = $data;
            }
        };
    }

    /**
     * Doctrine assigns identifiers; this test has no database.
     *
     * Reflection is used here and nowhere else in the suite. docs/testing.md
     * says to assert on observable outcomes rather than reach into internals,
     * and this does not assert on anything — it constructs the state a persisted
     * entity would be in, so the observable outcome can be asserted normally.
     */
    private function assignIdentifier(User $user, int $id): void
    {
        $property = new ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);
    }
}
