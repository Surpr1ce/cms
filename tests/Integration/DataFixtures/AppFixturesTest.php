<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Repository\MediaRepository;
use App\Service\Media\MediaStorage;

use function count;

use const DIRECTORY_SEPARATOR;

use Doctrine\ORM\EntityManagerInterface;

use function is_dir;
use function is_string;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Loading the development dataset, files and all.
 *
 * `AppStoryTest` covers the content; this covers the two things the fixture does
 * on top of it, both of which are about the disk and neither of which any other
 * test touches.
 *
 * **Every catalogued file gets bytes.** The factories catalogue files without
 * uploading any, which is right for a test — but an installation whose articles
 * all show a missing image looks broken rather than empty, and somebody setting
 * the project up cannot tell the difference.
 *
 * **What the previous dataset left behind is cleared out.** `doctrine:fixtures:load`
 * purges the database and does not touch the disk, so every reload used to leave a
 * full set of uploads that nothing pointed at. That is safe here in a way it would
 * not be anywhere else — the database has just been emptied, so anything
 * uncatalogued is by definition the last dataset — and the rule that makes it safe
 * is the one asserted below: **a name that does not look generated is left alone.**
 * A fixture load is not entitled to an opinion about a file a person put there.
 */
final class AppFixturesTest extends KernelTestCase
{
    use Factories;

    private AppFixtures $fixtures;

    private MediaStorage $storage;

    private MediaRepository $media;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $fixtures = $container->get(AppFixtures::class);
        self::assertInstanceOf(AppFixtures::class, $fixtures);
        $this->fixtures = $fixtures;

        $storage = $container->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);
        $this->storage = $storage;

        $media = $container->get(MediaRepository::class);
        self::assertInstanceOf(MediaRepository::class, $media);
        $this->media = $media;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->emptyStorage();
    }

    protected function tearDown(): void
    {
        $this->emptyStorage();

        parent::tearDown();
    }

    public function testEveryCatalogedFileEndsUpWithBytesOnDisk(): void
    {
        $this->fixtures->load($this->entityManager);

        $catalogued = $this->media->findAll();

        self::assertNotEmpty($catalogued);

        foreach ($catalogued as $media) {
            self::assertTrue(
                $this->storage->exists($media),
                $media->getFilename().' was catalogued with nothing behind it.',
            );
        }
    }

    /**
     * The bytes have to match the type the record claims, because files are
     * served with the recorded type and `nosniff` — so a browser told "JPEG" and
     * handed a PNG refuses to render it rather than working it out.
     */
    public function testTheBytesMatchTheTypeTheRecordClaims(): void
    {
        $this->fixtures->load($this->entityManager);

        foreach ($this->media->findAll() as $media) {
            if ('application/pdf' === $media->getMimeType()) {
                continue;
            }

            $size = getimagesizefromstring((string) file_get_contents($this->storage->pathFor($media)));

            self::assertIsArray($size, $media->getFilename().' is not an image at all.');
            self::assertSame(
                $media->getMimeType(),
                $size['mime'],
                $media->getFilename().' is catalogued as '.$media->getMimeType().'.',
            );
        }
    }

    public function testAFileFromAPreviousDatasetIsCleanedUp(): void
    {
        // A name of the shape this application generates, catalogued by nothing.
        $orphan = $this->storage->directory().DIRECTORY_SEPARATOR.str_repeat('a', 32).'.png';
        $this->write($orphan, 'stale');

        $this->fixtures->load($this->entityManager);

        self::assertFileDoesNotExist($orphan);
    }

    /**
     * The assertion that makes the clean-up defensible. Anything not shaped like
     * a generated name was put there by a person, and this runs unattended.
     */
    public function testAFileAPersonPutThereIsLeftAlone(): void
    {
        $theirs = $this->storage->directory().DIRECTORY_SEPARATOR.'notes-from-the-meeting.png';
        $this->write($theirs, 'not ours to delete');

        $this->fixtures->load($this->entityManager);

        self::assertFileExists($theirs);
        self::assertSame('not ours to delete', file_get_contents($theirs));
    }

    public function testLoadingTwiceLeavesTheSameFilesBehind(): void
    {
        $this->fixtures->load($this->entityManager);
        $after = $this->filesOnDisk();

        $this->fixtures->load($this->entityManager);

        // Not "no files changed" — the second load catalogues new records with
        // new generated names, and the point is that the first load's files went
        // with them rather than accumulating.
        self::assertSame($after, $this->filesOnDisk());
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir($this->storage->directory())) {
            mkdir($this->storage->directory(), 0o777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @return int how many files are in the upload directory
     */
    private function filesOnDisk(): int
    {
        if (!is_dir($this->storage->directory())) {
            return 0;
        }

        return count((array) glob($this->storage->directory().DIRECTORY_SEPARATOR.'*'));
    }

    private function emptyStorage(): void
    {
        if (!is_dir($this->storage->directory())) {
            return;
        }

        foreach ((array) glob($this->storage->directory().DIRECTORY_SEPARATOR.'*') as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
}
