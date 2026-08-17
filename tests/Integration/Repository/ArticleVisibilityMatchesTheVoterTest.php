<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Article;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use App\Security\ArticleVoter;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The article list's visibility rule, written twice, asserted to be one rule.
 *
 * `ArticleRepository::findPageForViewer()` is `ArticleVoter::canView()` expressed
 * as SQL. That duplication is deliberate — the administration list cannot be
 * paginated while it is filtered after fetching, because twenty fetched rows
 * would show as six — and this test is the whole reason it is safe. Both are run
 * over the same articles, for every combination of roles and ownership, and their
 * answers are compared. If either changes without the other, this goes red.
 *
 * SC-002. Note what is *not* asserted: nothing here checks that a particular
 * article is visible to a particular person. That is ArticleVoterTest's job, and
 * duplicating it would mean maintaining the matrix twice. What is asserted is
 * agreement — so the voter stays the single statement of the rule and the query
 * stays a translation of it.
 */
final class ArticleVisibilityMatchesTheVoterTest extends KernelTestCase
{
    use Factories;

    /**
     * Above anything the fixture creates, so "a page" means "all of it" wherever
     * paging is not what is being tested.
     */
    private const int EVERYTHING = 100;

    private ArticleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        $this->repository = $repository;
    }

    /**
     * Roles rather than accounts, because a provider runs before the kernel boots
     * and cannot create an entity. The account is built inside the test, and it
     * always owns some of the articles — so ownership is exercised on every row
     * of this provider rather than in a case of its own.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function viewerProvider(): iterable
    {
        yield 'an author' => [[User::ROLE_AUTHOR]];
        yield 'an editor' => [[User::ROLE_EDITOR]];
        yield 'an administrator' => [[User::ROLE_ADMIN]];
        yield 'an account holding no roles at all' => [[]];

        // An account that writes and edits. Both branches of the query apply, and
        // the editorial one has to win rather than narrow it to their own work.
        yield 'an author who is also an editor' => [[User::ROLE_AUTHOR, User::ROLE_EDITOR]];

        // A role this application has never heard of grants nothing, so this
        // viewer must see exactly what a roleless one sees. The query decides
        // that by naming the three roles it knows rather than by counting them.
        yield 'an invented role' => [['ROLE_SUPERUSER']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('viewerProvider')]
    public function testTheQueryReturnsWhatTheVoterWouldAllow(array $roles): void
    {
        $viewer = $this->account($roles, 'viewer@example.com');
        $this->populate($viewer);

        $throughTheVoter = $this->everythingTheVoterAllows($viewer);
        $throughTheQuery = $this->repository->findPageForViewer($viewer, self::EVERYTHING, 0);

        self::assertSame(
            $this->slugsOf($throughTheVoter),
            $this->slugsOf($throughTheQuery),
            'The query and the voter disagree about what this viewer may see.',
        );

        // Otherwise two empty lists would agree, and the assertion above would
        // pass on a query that returns nothing at all.
        self::assertNotEmpty($throughTheQuery, 'Every viewer can see the published articles at least.');
    }

    /**
     * The same agreement, one page at a time.
     *
     * The identity above would hold for a query that filtered correctly and paged
     * wrongly — repeating a row on the next page, or skipping one. Walking the
     * whole list in pages of three and comparing the concatenation to what the
     * voter allows is what rules that out.
     *
     * @param list<string> $roles
     */
    #[DataProvider('viewerProvider')]
    public function testPagingThroughTheQueryVisitsEachAllowedArticleExactlyOnce(array $roles): void
    {
        $viewer = $this->account($roles, 'viewer@example.com');
        $this->populate($viewer);

        $perPage = 3;
        $walked = [];

        // Bounded rather than "until it is empty": a query that never runs out
        // would hang the suite instead of failing it.
        for ($offset = 0; $offset < self::EVERYTHING; $offset += $perPage) {
            $page = $this->repository->findPageForViewer($viewer, $perPage, $offset);

            if ([] === $page) {
                break;
            }

            $walked = array_merge($walked, $page);
        }

        self::assertSame(
            $this->slugsOf($this->everythingTheVoterAllows($viewer)),
            $this->slugsOf($walked),
            'Read in pages, the query shows something other than what the voter allows.',
        );
    }

    /**
     * The rule an author is most likely to notice if it breaks, stated on its own
     * rather than left to the comparison: their own unpublished work is on the
     * list and nobody else's is.
     */
    public function testAnAuthorSeesTheirOwnDraftsAndNoOtherAuthorsThroughTheQuery(): void
    {
        $author = $this->account([User::ROLE_AUTHOR], 'author@example.com');
        $this->populate($author);

        $visible = $this->slugsOf($this->repository->findPageForViewer($author, self::EVERYTHING, 0));

        self::assertContains('theirs-draft', $visible);
        self::assertContains('theirs-unpublished-again', $visible);
        self::assertContains('somebody-elses-published', $visible);

        self::assertNotContains('somebody-elses-draft', $visible);
        self::assertNotContains('somebody-elses-archived', $visible);
    }

    /**
     * Eight articles: four written by the viewer and four by somebody else, in
     * each of the four states content can be in. Every row of the voter's matrix
     * that VIEW can reach is therefore present in the comparison.
     *
     * The dates are fixed and distinct so that both readings order the list
     * identically and a mismatch means a difference in *content*, not in sort
     * order.
     */
    private function populate(User $viewer): void
    {
        $somebodyElse = $this->account([User::ROLE_AUTHOR], 'somebody-else@example.com');

        $day = 1;

        foreach (['theirs' => $viewer, 'somebody-elses' => $somebodyElse] as $whose => $author) {
            ArticleFactory::createOne([
                'slug' => $whose.'-draft',
                'author' => $author,
                'createdAt' => $this->onDay($day++),
            ]);

            ArticleFactory::new()->published()->create([
                'slug' => $whose.'-published',
                'author' => $author,
                'createdAt' => $this->onDay($day++),
            ]);

            ArticleFactory::new()->publishedThenArchived()->create([
                'slug' => $whose.'-archived',
                'author' => $author,
                'createdAt' => $this->onDay($day++),
            ]);

            // Published once and taken down again. A draft by status, with a
            // publication date it keeps — the state that catches a rule reading
            // the date where it should read the status, or the reverse. The
            // unpublishing happens on instantiation so that what is stored is
            // already in that state, with nothing to flush afterwards.
            ArticleFactory::new()
                ->published()
                ->afterInstantiate(static function (Article $article): void {
                    $article->unpublish();
                })
                ->create([
                    'slug' => $whose.'-unpublished-again',
                    'author' => $author,
                    'createdAt' => $this->onDay($day++),
                ]);
        }
    }

    private function onDay(int $day): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-03-%02d 09:00:00', $day));
    }

    /**
     * Every article in the table filtered through the voter, in the order the
     * query promises. This is the list the administration screen used to build in
     * PHP, kept here as the definition of the right answer.
     *
     * @return list<Article>
     */
    private function everythingTheVoterAllows(User $viewer): array
    {
        $token = new UsernamePasswordToken($viewer, 'main', $viewer->getRoles());
        $voter = new ArticleVoter();

        /** @var list<Article> $everything */
        $everything = array_values($this->repository->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']));

        return array_values(array_filter(
            $everything,
            static fn (Article $article): bool => VoterInterface::ACCESS_GRANTED === $voter->vote(
                $token,
                $article,
                [ArticleVoter::VIEW],
            ),
        ));
    }

    /**
     * Slugs rather than identifiers, so a failure names the articles that differ
     * instead of printing two lists of numbers.
     *
     * @param list<Article> $articles
     *
     * @return list<string>
     */
    private function slugsOf(array $articles): array
    {
        return array_map(static fn (Article $article): string => $article->getSlug(), $articles);
    }

    /**
     * @param list<string> $roles
     */
    private function account(array $roles, string $email): User
    {
        return UserFactory::createOne(['email' => $email, 'roles' => $roles]);
    }
}
