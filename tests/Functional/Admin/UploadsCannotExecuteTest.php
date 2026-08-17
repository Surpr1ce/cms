<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Media;
use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\MediaRepository;
use App\Service\Media\MediaStorage;

use const DIRECTORY_SEPARATOR;

use function dirname;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * The requirement this whole feature is judged on.
 *
 * An uploads directory that will execute a PHP file is a remote shell, reachable
 * by anybody who can sign in. Every assertion here looks at **what reached disk
 * and the catalogue**, never at what a screen said — a test that checked for an
 * error message would pass with the file written anyway, which is the failure it
 * is supposed to catch.
 */
final class UploadsCannotExecuteTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        foreach ($this->filesOnDisk() as $name) {
            unlink($this->storage()->directory().DIRECTORY_SEPARATOR.$name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->filesOnDisk() as $name) {
            unlink($this->storage()->directory().DIRECTORY_SEPARATOR.$name);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileFileProvider(): iterable
    {
        yield 'PHP source, honestly named' => ["<?php system(\$_GET['c']); ?>", 'evil.php'];
        yield 'PHP source pretending to be a JPEG' => ["<?php system(\$_GET['c']); ?>", 'photo.jpg'];
        yield 'PHP source with a double extension' => ['<?php echo 1;', 'photo.jpg.php'];
        yield 'an SVG carrying script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>steal()</script></svg>', 'a.svg'];
        yield 'an HTML document' => ['<!DOCTYPE html><html><body>x</body></html>', 'page.html'];
        yield 'a shell script' => ["#!/bin/sh\nrm -rf /", 'run.sh'];
        yield 'a Windows executable header' => ["MZ\x90\x00\x03", 'evil.exe'];
        yield 'an image with source appended' => [self::pngBytes()."\n<?php system(\$_GET['c']); ?>", 'photo.png'];
        yield 'an empty file' => ['', 'nothing.png'];
    }

    /**
     * FR-007: neither a row nor a file.
     */
    #[DataProvider('hostileFileProvider')]
    public function testAHostileFileIsNeitherCataloguedNorWritten(string $contents, string $name): void
    {
        $this->signInAsEditor();
        $before = $this->filesOnDisk();

        $this->upload($contents, $name, 'A description');

        self::assertCount(0, $this->repository()->findAll(), sprintf('"%s" was catalogued.', $name));
        self::assertSame($before, $this->filesOnDisk(), sprintf('"%s" reached the disk.', $name));
    }

    public function testAnAcceptedImageIsCatalogued(): void
    {
        $this->signInAsEditor();

        $this->upload(self::pngBytes(), 'photo.png', 'A description');

        self::assertCount(1, $this->repository()->findAll());
    }

    /**
     * FR-008 and FR-009, with real bytes this time. The supplied name is kept as
     * a label and reaches no path.
     */
    public function testTheStoredNameIsGeneratedAndTheSuppliedNameIsOnlyALabel(): void
    {
        $this->signInAsEditor();

        $this->upload(self::pngBytes(), 'holiday snap.png', 'A description');

        $media = $this->onlyFile();

        self::assertSame('holiday snap.png', $media->getOriginalName());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $media->getFilename());
        self::assertStringNotContainsString('holiday', $media->getFilename());
    }

    /**
     * SC-003. A traversal attempt does not need to be rejected, because the name
     * is never read — the file lands under a generated name in the expected
     * directory like any other.
     *
     * @param string $suppliedName a name that would be dangerous if it were used
     */
    #[DataProvider('hostileNameProvider')]
    public function testASuppliedNameNeverInfluencesThePathOnDisk(string $suppliedName): void
    {
        $this->signInAsEditor();

        $this->upload(self::pngBytes(), $suppliedName, 'A description');

        $media = $this->onlyFile();
        $storage = $this->storage();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $media->getFilename());
        self::assertTrue($storage->exists($media));
        self::assertSame(
            realpath($storage->directory()),
            realpath(dirname($storage->pathFor($media))),
            'The file did not land in the uploads directory.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNameProvider(): iterable
    {
        yield 'parent traversal' => ['../../public/shell.php'];
        yield 'absolute unix path' => ['/etc/passwd'];
        yield 'absolute windows path' => ['C:\\Windows\\System32\\evil.png'];
        yield 'backslash traversal' => ['..\\..\\public\\shell.php'];
        yield 'a leading dot' => ['.htaccess'];
    }

    /**
     * SC-002, asserted rather than assumed. If the uploads directory ever moved
     * inside public/, every other protection in this feature would be decoration.
     */
    public function testTheStorageDirectoryIsOutsideTheWebRoot(): void
    {
        $storage = $this->storage();

        $uploads = realpath($storage->directory());
        $webRoot = realpath(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'public');

        self::assertIsString($uploads);
        self::assertIsString($webRoot);
        self::assertStringStartsNotWith($webRoot, $uploads, 'Uploads are inside the web root.');
    }

    /**
     * FR-022: nothing that a server could be persuaded to run is in there.
     */
    public function testNothingInTheStorageDirectoryHasAnExecutableExtension(): void
    {
        $this->signInAsEditor();
        $this->upload(self::pngBytes(), 'photo.png', 'A description');

        foreach ($this->filesOnDisk() as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/\.(php\d?|phtml|phar|cgi|pl|py|sh|exe|asp|aspx|jsp|htaccess)$/i',
                $name,
                sprintf('"%s" is in the uploads directory.', $name),
            );
        }
    }

    private function signInAsEditor(): User
    {
        $user = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'editor@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();

        return $user;
    }

    /**
     * Posts the upload directly rather than through the crawler's form object.
     *
     * The crawler's file field rebuilds an UploadedFile from the temporary path,
     * which replaces the *supplied* name with the temporary file's — and the
     * supplied name is exactly what these tests are about. Posting with an
     * explicit UploadedFile keeps the two apart, the way a browser does.
     */
    private function upload(string $contents, string $suppliedName, string $altText): void
    {
        $crawler = $this->client->request('GET', '/admin/media');
        $token = (string) $crawler->filter('form[action$="/admin/media/upload"] input[name="_token"]')->attr('value');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-upload-'.bin2hex(random_bytes(8));
        file_put_contents($path, $contents);

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

    private function storage(): MediaStorage
    {
        $storage = self::getContainer()->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        return $storage;
    }

    private function onlyFile(): Media
    {
        $all = $this->repository()->findAll();
        self::assertCount(1, $all);

        return $all[0];
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

    private static function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }
}
