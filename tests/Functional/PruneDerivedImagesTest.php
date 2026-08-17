<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\MediaFactory;
use App\Service\Media\MediaStorage;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function file_put_contents;
use function is_file;
use function random_bytes;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;

/**
 * Removing resized images nothing points at.
 *
 * The assertions worth reading are the ones about what is **kept**. A pruner
 * that removes everything passes any test that only checks orphans are gone, and
 * would quietly delete the whole cache every time somebody ran it — which is
 * survivable, because a derived image can be made again, and is still exactly
 * the kind of thing nobody notices for a month.
 *
 * So: a live derived image survives, and so does a file whose name this
 * application does not recognise. A pruner that deletes what it does not
 * understand is a pruner nobody should be willing to run.
 */
final class PruneDerivedImagesTest extends KernelTestCase
{
    use Factories;

    private string $directory;

    private Filesystem $filesystem;

    protected function setUp(): void
    {
        self::bootKernel();

        $storage = self::getContainer()->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        $this->directory = $storage->directory().DIRECTORY_SEPARATOR.'derived';
        $this->filesystem = new Filesystem();

        // Emptied, because these tests write real files and the filesystem is
        // not rolled back the way the database is. Safe only because the test
        // environment has an uploads directory of its own — see
        // config/services.yaml, which it did not until this test needed it.
        $this->filesystem->remove($this->directory);
        $this->filesystem->mkdir($this->directory);
    }

    public function testAnOrphanIsRemoved(): void
    {
        $orphan = $this->write('thumbnail-'.str_repeat('a', 32).'.jpg');

        $this->prune();

        self::assertFileDoesNotExist($orphan);
    }

    /**
     * The assertion this file exists for. Everything derived from something that
     * is still catalogued has to survive.
     */
    public function testALiveDerivedImageIsKept(): void
    {
        $media = MediaFactory::createOne(['filename' => bin2hex(random_bytes(16)).'.jpg']);

        $live = $this->write('thumbnail-'.$media->getFilename());
        $orphan = $this->write('thumbnail-'.str_repeat('b', 32).'.jpg');

        $this->prune();

        self::assertFileExists($live);
        self::assertFileDoesNotExist($orphan);
    }

    /**
     * A size that no longer exists is an orphan too — the file can never be
     * addressed again, because routing refuses a size that is not in the enum.
     */
    public function testAFileNamedForASizeThatNoLongerExistsIsRemoved(): void
    {
        $media = MediaFactory::createOne(['filename' => bin2hex(random_bytes(16)).'.jpg']);

        $retired = $this->write('enormous-'.$media->getFilename());

        $this->prune();

        self::assertFileDoesNotExist($retired);
    }

    /**
     * Anything unrecognised is left where it is. Somebody else may have put it
     * there, and guessing is how a maintenance command becomes a data loss
     * incident.
     */
    public function testAFileItCannotParseIsLeftAlone(): void
    {
        $strangers = [
            $this->write('notes.txt'),
            $this->write('README'),
        ];

        $this->prune();

        foreach ($strangers as $stranger) {
            self::assertFileExists($stranger);
        }
    }

    public function testADryRunRemovesNothing(): void
    {
        $orphan = $this->write('thumbnail-'.str_repeat('c', 32).'.jpg');

        $tester = $this->prune(['--dry-run' => true]);

        self::assertFileExists($orphan);
        self::assertStringContainsString('would remove', $tester->getDisplay());
    }

    public function testItSaysSoWhenThereIsNothingToDo(): void
    {
        $tester = $this->prune();

        self::assertStringContainsString('Nothing to remove', $tester->getDisplay());
    }

    /**
     * @param array<string, bool|string> $arguments
     */
    private function prune(array $arguments = []): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('app:media:prune-derived'));

        $tester->execute($arguments);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function write(string $name): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;

        file_put_contents($path, 'not really an image');

        self::assertTrue(is_file($path));

        return $path;
    }
}
