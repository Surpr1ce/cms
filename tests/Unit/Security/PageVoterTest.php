<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Page;
use App\Entity\User;
use App\Security\PageVoter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * FR-022: a page is governed by role alone.
 *
 * There is no ownership branch to test because there is no owner — which is the
 * reason `Page` is a separate entity rather than an article with a type flag.
 * The interesting assertion is therefore the one that proves an author gets
 * nothing, whoever they are.
 */
final class PageVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, bool, string, bool}>
     */
    public static function matrixProvider(): iterable
    {
        $author = [User::ROLE_AUTHOR];
        $editor = [User::ROLE_EDITOR];
        $admin = [User::ROLE_ADMIN];

        // An author has no claim on a page at all.
        yield 'author cannot edit a page' => [$author, false, PageVoter::EDIT, false];
        yield 'author cannot delete a page' => [$author, false, PageVoter::DELETE, false];
        yield 'author cannot publish a page' => [$author, false, PageVoter::PUBLISH, false];
        yield 'author cannot view a draft page' => [$author, false, PageVoter::VIEW, false];

        yield 'editor edits a page' => [$editor, false, PageVoter::EDIT, true];
        yield 'editor deletes a page' => [$editor, false, PageVoter::DELETE, true];
        yield 'editor publishes a page' => [$editor, false, PageVoter::PUBLISH, true];
        yield 'editor views a draft page' => [$editor, false, PageVoter::VIEW, true];

        yield 'admin edits a page' => [$admin, false, PageVoter::EDIT, true];
        yield 'admin deletes a page' => [$admin, false, PageVoter::DELETE, true];
        yield 'admin publishes a page' => [$admin, false, PageVoter::PUBLISH, true];
        yield 'admin views a draft page' => [$admin, false, PageVoter::VIEW, true];

        yield 'no roles cannot edit a page' => [[], false, PageVoter::EDIT, false];
        yield 'no roles cannot publish a page' => [[], false, PageVoter::PUBLISH, false];
        yield 'invented role cannot edit a page' => [['ROLE_SUPERUSER'], false, PageVoter::EDIT, false];

        // A published page is readable by anybody signed in, as on the public
        // site — the voter must not be stricter than the site it protects.
        yield 'author views a published page' => [$author, true, PageVoter::VIEW, true];
        yield 'no roles views a published page' => [[], true, PageVoter::VIEW, true];

        // Being published does not make it editable.
        yield 'author cannot edit a published page' => [$author, true, PageVoter::EDIT, false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('matrixProvider')]
    public function testThePermissionMatrix(array $roles, bool $published, string $permission, bool $expected): void
    {
        $decision = new PageVoter()->vote(
            new UsernamePasswordToken($this->account($roles), 'main', $roles),
            $this->page($published),
            [$permission],
        );

        self::assertSame(
            $expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $decision,
        );
    }

    public function testAQuestionAboutAnArticleIsAbstainedFrom(): void
    {
        $roles = [User::ROLE_ADMIN];

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new PageVoter()->vote(
                new UsernamePasswordToken($this->account($roles), 'main', $roles),
                new stdClass(),
                [PageVoter::EDIT],
            ),
        );
    }

    public function testAnUnknownPermissionIsAbstainedFrom(): void
    {
        $roles = [User::ROLE_ADMIN];

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new PageVoter()->vote(
                new UsernamePasswordToken($this->account($roles), 'main', $roles),
                $this->page(false),
                ['PAGE_INVENTED'],
            ),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function account(array $roles): User
    {
        $user = new User('person@example.com', 'A Person', new DateTimeImmutable('2026-01-01 00:00:00'));
        $user->setRoles($roles);

        return $user;
    }

    private function page(bool $published): Page
    {
        $page = new Page('About us', 'about-us', new DateTimeImmutable('2026-04-01 09:00:00'));
        $page->setContent('Who we are.');

        if ($published) {
            $page->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        }

        return $page;
    }
}
