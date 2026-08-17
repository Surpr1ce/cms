<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The body field, with the visual editor attached to it.
 *
 * A functional test cannot press a toolbar button — there is no browser here to
 * run `assets/editor.js`. What it can prove is the property the editor was built
 * around, and it is the one worth proving: **the field is still a text area, it
 * still submits a string, and that string is still sanitised on the way in.**
 *
 * That is the whole design. The editor is an enhancement in a browser that
 * writes into a control which already worked; it holds no authority, so a
 * request that never ran it is indistinguishable from one that did. These
 * assertions are therefore also the assertions that the editor cannot be used to
 * get anything past the sanitiser — because nothing it does reaches the server
 * except the contents of this field.
 *
 * What the toolbar can produce is covered in ContentSanitiserTest, where every
 * element it writes is asserted to survive. The two together are the contract.
 */
final class MarkupEditorTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * The enhancement is opt-in through an attribute, so a field that lost it
     * would silently stop offering the toolbar with nothing else changing.
     */
    public function testTheBodyFieldAsksForTheEditor(): void
    {
        $this->signInAsEditor();

        $crawler = $this->client->request('GET', '/admin/articles/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('textarea[data-markup-editor]'));
    }

    /**
     * The summary deliberately does not get one. It is stored with its tags
     * stripped, so a toolbar there would offer formatting the storage refuses.
     */
    public function testTheSummaryFieldDoesNotAskForTheEditor(): void
    {
        $this->signInAsEditor();

        $crawler = $this->client->request('GET', '/admin/articles/new');

        self::assertCount(0, $crawler->filter('#article_excerpt[data-markup-editor]'));
    }

    public function testThePageBodyAsksForItToo(): void
    {
        $this->signInAsEditor();

        $crawler = $this->client->request('GET', '/admin/pages/new');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('textarea[data-markup-editor]'));
    }

    /**
     * SC-004. Nothing about the field requires JavaScript: it is a text area,
     * and submitting it saves.
     */
    public function testTheFieldStillSavesWithoutAnythingRunningInABrowser(): void
    {
        $this->signInAsEditor();

        $crawler = $this->client->request('GET', '/admin/articles/new');
        $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => 'Written without a browser',
            'article[content]' => '<p>An ordinary paragraph.</p>',
        ]));

        self::assertResponseRedirects();
        self::assertSame('<p>An ordinary paragraph.</p>', $this->onlyArticle()->getContent());
    }

    /**
     * The assertion that the editor changes nothing about where safety comes
     * from. Whatever a browser puts in this field, the server cleans it.
     */
    public function testWhateverArrivesInTheFieldIsStillSanitised(): void
    {
        $this->signInAsEditor();
        $article = ArticleFactory::createOne(['title' => 'A draft', 'content' => '<p>Before.</p>']);

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $fields = $values['article'] ?? [];
        self::assertIsArray($fields);

        $fields['content'] = '<p>Kept.</p><script>alert(1)</script><p onclick="alert(2)">Also kept.</p>';
        $values['article'] = $fields;

        $this->client->request('POST', $form->getUri(), $values);

        $stored = $this->onlyArticle()->getContent();

        self::assertStringContainsString('Kept.', $stored);
        self::assertStringContainsString('Also kept.', $stored);
        self::assertStringNotContainsString('script', $stored);
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('alert', $stored);
    }

    private function onlyArticle(): Article
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $all = $entityManager->getRepository(Article::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function signInAsEditor(): void
    {
        UserFactory::new()->withPassword()->create([
            'email' => 'editor@example.com',
            'roles' => [User::ROLE_EDITOR],
        ]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'editor@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();
    }
}
