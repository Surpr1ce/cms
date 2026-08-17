<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Tag;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use App\Service\Slug\SlugGenerator;

use function count;

use DateTimeImmutable;

use function sprintf;

use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * A development dataset built entirely from the factories, so that fixtures and
 * tests agree about what a valid entity looks like.
 *
 * Deliberately not uniform: content in all three states, sections nested two
 * deep, pages with an explicit menu order, and files both with and without
 * alternative text. A dataset where everything is published and everything is
 * described makes every screen look correct, including the ones that are not.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    public function build(): void
    {
        $now = new DateTimeImmutable();
        $slugger = new SlugGenerator();

        // Every fixture account can sign in, with the password written openly in
        // UserFactory. That it is in the repository is the point: an account
        // whose password anybody can read is an account nobody can mistake for a
        // real one. Real accounts are created with app:create-administrator.
        $admin = UserFactory::new()->admin()->withPassword()->create([
            'email' => 'admin@example.com',
            'displayName' => 'Alex Admin',
        ]);
        $editor = UserFactory::new()->editor()->withPassword()->create([
            'email' => 'editor@example.com',
            'displayName' => 'Erin Editor',
        ]);
        $authors = UserFactory::new()->author()->withPassword()->many(2)->create();

        $news = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $opinion = CategoryFactory::createOne(['name' => 'Opinion', 'slug' => 'opinion']);
        $releases = CategoryFactory::new()->childOf($news)->create([
            'name' => 'Releases',
            'slug' => 'releases',
        ]);

        $tags = [];
        foreach (['PHP', 'Symfony', 'Doctrine', 'Twig', 'PostgreSQL'] as $name) {
            $tags[] = TagFactory::createOne(['name' => $name, 'slug' => $slugger->generate($name)]);
        }

        $described = MediaFactory::createMany(4, ['uploadedBy' => $editor]);
        MediaFactory::new()->withoutAltText()->create(['uploadedBy' => $admin]);
        MediaFactory::new()->pdf()->create(['uploadedBy' => $editor]);

        $this->articles($now, $slugger, [$admin, $editor, ...$authors], [$news, $opinion, $releases], $tags, $described);
        $this->pages($now, $slugger);
    }

    /**
     * @param list<User>     $writers
     * @param list<Category> $sections
     * @param list<Tag>      $tags
     * @param list<Media>    $images
     */
    private function articles(
        DateTimeImmutable $now,
        SlugGenerator $slugger,
        array $writers,
        array $sections,
        array $tags,
        array $images,
    ): void {
        $titles = [
            'Symfony 8.1 arrives with a slimmer kernel',
            'What Doctrine ORM 3 changes for entity design',
            'Reading a query plan before reaching for an index',
            'Why our slugs stop changing after publication',
            'PostgreSQL 16 on Windows, without Docker',
            'Twig components, one year in',
            'The case against a headless CMS for a small team',
            'How we test failure paths first',
            'A draft nobody has finished yet',
            'Another unfinished thought',
            'Retired: our old deployment process',
            'Retired: the first API design',
        ];

        foreach ($titles as $index => $title) {
            $article = ArticleFactory::createOne([
                'title' => $title,
                'slug' => $slugger->generate($title),
                'author' => $writers[$index % count($writers)],
                'category' => $sections[$index % count($sections)],
                'createdAt' => $now->modify(sprintf('-%d days', 40 - $index)),
            ]);

            $article->addTag($tags[$index % count($tags)]);
            $article->addTag($tags[($index + 2) % count($tags)]);

            if ($index < count($images)) {
                $article->setFeaturedImage($images[$index]);
            }

            $this->stage($article, $index, $now);
        }
    }

    private function stage(Article $article, int $index, DateTimeImmutable $now): void
    {
        // Two of every three are published, two are left as drafts, and the last
        // two are archived after having been published — so listings, the
        // administration area and the archive each have something to show.
        if (str_starts_with($article->getTitle(), 'Retired: ')) {
            $article->publish($now->modify(sprintf('-%d days', 200 - $index)));
            $article->archive();

            return;
        }

        if (str_contains($article->getTitle(), 'draft') || str_contains($article->getTitle(), 'unfinished')) {
            return;
        }

        $article->publish($now->modify(sprintf('-%d days', 30 - $index)));
    }

    private function pages(DateTimeImmutable $now, SlugGenerator $slugger): void
    {
        $about = $this->page('About us', 10, $now, $slugger);
        $this->page('Contact', 20, $now, $slugger);
        $this->page('Privacy', 30, $now, $slugger);

        $team = $this->page('Our team', 10, $now, $slugger);
        $team->setParent($about);

        $history = $this->page('History', 20, $now, $slugger);
        $history->setParent($about);

        // One page left as a draft, so the public menu and the administration
        // list are not the same list.
        PageFactory::createOne([
            'title' => 'Terms of service',
            'slug' => 'terms-of-service',
            'createdAt' => $now->modify('-10 days'),
        ]);
    }

    private function page(string $title, int $menuOrder, DateTimeImmutable $now, SlugGenerator $slugger): Page
    {
        return PageFactory::new()->published()->create([
            'title' => $title,
            'slug' => $slugger->generate($title),
            'menuOrder' => $menuOrder,
            'createdAt' => $now->modify('-60 days'),
        ]);
    }
}
