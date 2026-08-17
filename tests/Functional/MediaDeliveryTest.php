<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Media;
use App\Factory\MediaFactory;
use App\Service\Media\ImageSize;
use App\Service\Media\MediaStorage;

use const DIRECTORY_SEPARATOR;

use function getimagesizefromstring;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagejpeg;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function strlen;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Caching, and images arriving at the size they are shown.
 *
 * Neither half of this feature may weaken what feature 005 proved, so both
 * halves are checked for the same headers the original response carries — a
 * derived image is still a file this application wrote, and "it's only a
 * thumbnail" is how a directory that cannot execute anything acquires one that
 * can.
 */
final class MediaDeliveryTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // ------------------------------------------------------------- caching

    /**
     * FR-001 and FR-003.
     */
    public function testAServedFileMayBeKeptForALongTime(): void
    {
        $media = $this->storedImage(600, 400);

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseIsSuccessful();

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertNotNull($this->client->getResponse()->headers->get('ETag'));
        self::assertNotNull($this->client->getResponse()->headers->get('Last-Modified'));
    }

    /**
     * FR-002, and the assertion the caching half exists for. A header claiming
     * something is cacheable is worth nothing if asking again still sends the
     * bytes.
     */
    public function testAReaderHoldingTheValidatorReceivesNoBytes(): void
    {
        $media = $this->storedImage(600, 400);
        $address = '/media/'.$media->getFilename();

        $this->client->request('GET', $address);
        $etag = (string) $this->client->getResponse()->headers->get('ETag');

        $this->client->request('GET', $address, server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        self::assertEmpty((string) $this->client->getResponse()->getContent());
    }

    public function testTheSameAppliesToADocument(): void
    {
        $media = MediaFactory::createOne([
            'filename' => str_repeat('b', 32).'.pdf',
            'mimeType' => 'application/pdf',
        ]);
        $this->write($media, "%PDF-1.7\ntrailer\n%%EOF\n");

        $this->client->request('GET', '/media/'.$media->getFilename());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * FR-004. A derived image is cached exactly as an original is.
     */
    public function testADerivedImageIsCachedTheSameWay(): void
    {
        $media = $this->storedImage(1200, 800);

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($this->client->getResponse()->headers->get('ETag'));
    }

    // ------------------------------------------------------- derived sizes

    /**
     * FR-006 and SC-003. Within the box, proportions unchanged, nothing
     * cropped.
     */
    public function testAThumbnailFitsWithinTheSizeWithoutBeingStretched(): void
    {
        $media = $this->storedImage(1200, 600);

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());

        [$width, $height] = $this->dimensionsOfTheResponse();

        self::assertLessThanOrEqual(ImageSize::Thumbnail->longestSide(), $width);
        self::assertLessThanOrEqual(ImageSize::Thumbnail->longestSide(), $height);
        // 1200x600 is two to one, and so is whatever comes back.
        self::assertSame(2.0, round($width / $height, 2));
    }

    public function testEverySizeIsWithinItsOwnBounds(): void
    {
        $media = $this->storedImage(3000, 2000);

        foreach (ImageSize::cases() as $size) {
            $this->client->request('GET', sprintf('/media/%s/%s', $size->value, $media->getFilename()));

            self::assertResponseIsSuccessful($size->value.' was not served.');

            [$width, $height] = $this->dimensionsOfTheResponse();

            self::assertLessThanOrEqual($size->longestSide(), max($width, $height), $size->value.' is too big.');
        }
    }

    /**
     * FR-007. An enlarged image is a blurrier copy at a larger file size, which
     * is the opposite of the point.
     */
    public function testAnImageSmallerThanTheSizeIsNotEnlarged(): void
    {
        $media = $this->storedImage(120, 90);

        $this->client->request('GET', '/media/large/'.$media->getFilename());

        self::assertSame([120, 90], $this->dimensionsOfTheResponse());
    }

    /**
     * FR-008. A dimension a reader can name is a dimension a reader can ask the
     * server to generate ten thousand times.
     */
    public function testASizeNobodyDefinedIsNotServed(): void
    {
        $media = $this->storedImage(600, 400);

        foreach (['enormous', '4000', 'thumbnail2', '../original'] as $invented) {
            $this->client->request('GET', sprintf('/media/%s/%s', $invented, $media->getFilename()));

            self::assertResponseStatusCodeSame(404, sprintf('"%s" was served.', $invented));
        }
    }

    /**
     * FR-011. A PDF has no thumbnail, and saying "not found" is the truth.
     */
    public function testAFileThatIsNotAnImageHasNoSizes(): void
    {
        $media = MediaFactory::createOne([
            'filename' => str_repeat('c', 32).'.pdf',
            'mimeType' => 'application/pdf',
        ]);
        $this->write($media, "%PDF-1.7\ntrailer\n%%EOF\n");

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FR-009. The second request must not do the work again — and must return
     * exactly the same bytes, which is what makes the caching headers honest.
     */
    public function testTheSameSizeIsProducedOnceAndReused(): void
    {
        $media = $this->storedImage(1200, 800);
        $address = '/media/medium/'.$media->getFilename();

        $this->client->request('GET', $address);
        $first = $this->responseBytes();
        $firstEtag = (string) $this->client->getResponse()->headers->get('ETag');

        $this->client->request('GET', $address);
        $second = $this->responseBytes();

        self::assertSame($first, $second);
        self::assertSame($firstEtag, (string) $this->client->getResponse()->headers->get('ETag'));
    }

    public function testADerivedImageIsSmallerThanTheOriginal(): void
    {
        $media = $this->storedImage(2400, 1600);

        $this->client->request('GET', '/media/'.$media->getFilename());
        $original = strlen($this->responseBytes());

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());
        $thumbnail = strlen($this->responseBytes());

        self::assertLessThan($original, $thumbnail);
    }

    /**
     * A record can outlive its bytes, and asking for a size of nothing must be
     * a "not found" rather than a failure part-way through resizing.
     */
    public function testARecordWhoseBytesAreGoneHasNoSizesEither(): void
    {
        $media = MediaFactory::createOne([
            'filename' => str_repeat('d', 32).'.jpg',
            'mimeType' => 'image/jpeg',
        ]);

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());

        self::assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------- the guarantees

    /**
     * FR-010 and FR-014. Everything feature 005 established, on the derived
     * route as well as the original one.
     */
    public function testADerivedImageCarriesTheSameGuaranteesAsAnOriginal(): void
    {
        $media = $this->storedImage(1200, 800);

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertStringContainsString(
            'inline',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    /**
     * The derived files are written where the originals are — outside the web
     * root — and nothing about that may be relaxed for a thumbnail.
     */
    public function testDerivedImagesAreWrittenOutsideTheWebRoot(): void
    {
        $media = $this->storedImage(1200, 800);

        $this->client->request('GET', '/media/thumbnail/'.$media->getFilename());
        self::assertResponseIsSuccessful();

        $storage = self::getContainer()->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        self::assertStringNotContainsString(
            DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR,
            $storage->directory(),
        );
    }

    /**
     * The route pattern is a compile-time string and cannot call the enum, so
     * this is what keeps the two from drifting apart. Adding a case without
     * touching the route fails here rather than producing a size nobody can
     * reach.
     */
    public function testTheRouteAcceptsExactlyTheSizesThatExist(): void
    {
        $router = self::getContainer()->get('router');
        self::assertNotNull($router);

        $route = $router->getRouteCollection()->get('media_sized');
        self::assertNotNull($route);

        self::assertSame(ImageSize::routePattern(), $route->getRequirement('size'));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function storedImage(int $width, int $height): Media
    {
        $media = MediaFactory::createOne([
            'filename' => bin2hex(random_bytes(16)).'.jpg',
            'mimeType' => 'image/jpeg',
        ]);

        $this->write($media, $this->jpegOf($width, $height));

        return $media;
    }

    private function write(Media $media, string $contents): void
    {
        $storage = self::getContainer()->get(MediaStorage::class);
        self::assertInstanceOf(MediaStorage::class, $storage);

        $storage->writeRaw($media, $contents);
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function jpegOf(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * The bytes of the response.
     *
     * A `BinaryFileResponse` streams from disk rather than holding its content,
     * so `getContent()` answers `false` — the file is never read into memory,
     * which is the point of using it. Sending it into an output buffer is how a
     * test gets at what a reader would receive.
     */
    private function responseBytes(): string
    {
        ob_start();
        $this->client->getResponse()->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @return array{int, int}
     */
    private function dimensionsOfTheResponse(): array
    {
        $dimensions = getimagesizefromstring($this->responseBytes());

        self::assertNotFalse($dimensions, 'The response is not an image.');

        return [$dimensions[0], $dimensions[1]];
    }
}
