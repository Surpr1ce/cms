<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\UserFactory;
use App\Repository\MediaRepository;
use App\Service\Media\MediaStorage;

use const DIRECTORY_SEPARATOR;

use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Serving files back, and the permissions on managing them.
 *
 * Reading is open to anybody, because a file used in a published article has to
 * be. That is stated in the specification rather than left to be inferred from
 * the absence of a check — the constitution says files are served "through a
 * controller that applies authorisation", and the authorisation this one applies
 * is "anybody may read".
 */
final class MediaServingTest extends WebTestCase
{
    use Factories;
    use SigningOut;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->emptyStorage();
    }

    protected function tearDown(): void
    {
        $this->emptyStorage();

        parent::tearDown();
    }

    public function testAStoredFileIsServedToAnybody(): void
    {
        $media = $this->uploadAsEditor();
        $this->signOut();

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseIsSuccessful();
    }

    public function testItIsServedWithTheRecordedType(): void
    {
        $media = $this->uploadAsEditor();

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    /**
     * FR-013. Without this header a browser may decide for itself what the bytes
     * are and render them as something else — which is what makes "we recorded
     * the type" mean anything at all.
     */
    public function testItIsServedWithTheHeaderThatStopsTheBrowserGuessing(): void
    {
        $media = $this->uploadAsEditor();

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testAnImageIsServedInline(): void
    {
        $media = $this->uploadAsEditor();

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertStringStartsWith(
            'inline',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testAnAddressThatMatchesNothingIsNotFound(): void
    {
        $this->client->request('GET', '/media/'.str_repeat('a', 32).'.png');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * FR-015: a record can outlive its bytes, and that is a 404 rather than an
     * error page.
     */
    public function testARecordWhoseBytesAreGoneIsNotFound(): void
    {
        $media = $this->uploadAsEditor();
        $this->storage()->remove($media);

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The route requirement admits only the shape a generated name has, so a
     * traversal attempt is refused by the router and never reaches a controller.
     */
    public function testATraversalAddressNeverReachesTheController(): void
    {
        foreach (['/media/../../.env', '/media/..%2F..%2F.env', '/media/index.php'] as $path) {
            $this->client->request('GET', $path);

            self::assertFalse(
                $this->client->getResponse()->isSuccessful(),
                sprintf('%s was served.', $path),
            );
        }
    }

    /**
     * SC-005, end to end: an image chosen in the article screen reaches a reader.
     */
    public function testALeadImageAppearsOnThePublishedArticle(): void
    {
        $media = $this->uploadAsEditor();

        ArticleFactory::new()->published()->create([
            'slug' => 'illustrated',
            'featuredImage' => $media,
        ]);

        $this->signOut();
        $crawler = $this->client->request('GET', '/articles/illustrated');

        self::assertResponseIsSuccessful();
        // Since feature 012 the article asks for the size its column shows,
        // not the size the photograph was uploaded at.
        self::assertSame(
            '/media/large/'.$media->getFilename(),
            $crawler->filter('main img')->attr('src'),
        );
    }

    // --- managing files is editorial ---

    public function testTheFileListIsClosedToSomebodyNotSignedIn(): void
    {
        $this->client->request('GET', '/admin/media');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnAuthorCannotReachTheFileList(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        $this->client->request('GET', '/admin/media');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAuthorCannotUploadBySubmittingDirectly(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        $this->client->request('POST', '/admin/media/upload', ['_token' => 'anything', 'altText' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(0, $this->repository()->findAll());
    }

    public function testAnUploadWithoutTheTokenIsRefused(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->client->request('POST', '/admin/media/upload', ['_token' => 'wrong', 'altText' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertCount(0, $this->repository()->findAll());
    }

    /**
     * FR-002: a file nobody can describe is a file nobody who cannot see it can
     * use, so it is refused before anything is written.
     */
    public function testAnUploadWithNoDescriptionIsRefusedAndStoresNothing(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $before = $this->filesOnDisk();
        $this->postUpload('', 'photo.png');

        self::assertCount(0, $this->repository()->findAll());
        self::assertSame($before, $this->filesOnDisk());
    }

    public function testDeletingAFileRemovesTheBytesAndLeavesTheArticle(): void
    {
        $media = $this->uploadAsEditor();
        ArticleFactory::new()->published()->create(['slug' => 'illustrated', 'featuredImage' => $media]);

        $path = $this->storage()->pathFor($media);
        self::assertFileExists($path);

        $crawler = $this->client->request('GET', '/admin/media');
        $this->client->submit($crawler->selectButton('Delete')->form());

        self::assertCount(0, $this->repository()->findAll());
        self::assertFileDoesNotExist($path);

        $this->client->request('GET', '/articles/illustrated');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): void
    {
        UserFactory::new()->withPassword()->create(['email' => 'person@example.com', 'roles' => $roles]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'person@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();
    }

    private function uploadAsEditor(): Media
    {
        $this->signIn([User::ROLE_EDITOR]);
        $this->postUpload('A description', 'photo.png');

        $all = $this->repository()->findAll();
        self::assertCount(1, $all, 'The upload did not succeed.');

        return $all[0];
    }

    private function postUpload(string $altText, string $suppliedName): void
    {
        $crawler = $this->client->request('GET', '/admin/media');
        $token = (string) $crawler->filter('form[action$="/admin/media/upload"] input[name="_token"]')->attr('value');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-serve-'.bin2hex(random_bytes(8));
        file_put_contents($path, $this->pngBytes());

        $this->client->request(
            'POST',
            '/admin/media/upload',
            ['altText' => $altText, '_token' => $token],
            ['file' => new UploadedFile($path, $suppliedName, null, null, true)],
        );

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private function filesOnDisk(): array
    {
        $directory = $this->storage()->directory();

        if (!is_dir($directory)) {
            return [];
        }

        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..', '.gitignore']));
        sort($entries);

        return $entries;
    }

    private function emptyStorage(): void
    {
        foreach ($this->filesOnDisk() as $name) {
            unlink($this->storage()->directory().DIRECTORY_SEPARATOR.$name);
        }
    }

    private function storage(): MediaStorage
    {
        $storage = self::getContainer()->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        return $storage;
    }

    private function repository(): MediaRepository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(MediaRepository::class);
        self::assertInstanceOf(MediaRepository::class, $repository);

        return $repository;
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }
}
