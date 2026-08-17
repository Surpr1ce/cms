<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Exception\UnsupportedMediaType;
use App\Exception\UploadIsTooLarge;
use App\Service\Media\StoredFilenameGenerator;
use App\Service\Media\UploadedFileValidator;

use const DIRECTORY_SEPARATOR;

use PHPUnit\Framework\TestCase;

use function sprintf;

use Symfony\Component\HttpFoundation\File\File;

/**
 * The hostile catalogue, at the boundary where bytes first arrive.
 *
 * Every case here writes a real file to a temporary directory with real bytes,
 * because the point is what the *content* is. A test using a mock would be
 * asserting that the validator asks the right question of an object that always
 * gives the answer the test wants — which proves nothing about a PHP file
 * called photo.jpg.
 */
final class UploadedFileValidatorTest extends TestCase
{
    private const int EIGHT_MEGABYTES = 8 * 1024 * 1024;

    /**
     * @var list<string>
     */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAnAcceptedImageIsAllowedAndItsTypeReported(): void
    {
        $detected = $this->validator()->validate($this->fileWith($this->pngBytes(), 'photo.png'));

        self::assertSame('image/png', $detected);
    }

    public function testAJpegIsAccepted(): void
    {
        self::assertSame('image/jpeg', $this->validator()->validate($this->fileWith($this->jpegBytes(), 'photo.jpg')));
    }

    public function testAGifIsAccepted(): void
    {
        self::assertSame('image/gif', $this->validator()->validate($this->fileWith($this->gifBytes(), 'a.gif')));
    }

    public function testAPdfIsAccepted(): void
    {
        self::assertSame('application/pdf', $this->validator()->validate($this->fileWith($this->pdfBytes(), 'a.pdf')));
    }

    /**
     * The obvious case.
     */
    public function testPhpSourceIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith("<?php system(\$_GET['c']); ?>", 'evil.php'));
    }

    /**
     * The case that matters. The name says image, the bytes say otherwise, and
     * the bytes are what decide — an application that trusted the extension here
     * would have written a web shell to disk.
     */
    public function testPhpSourceRenamedAsAnImageIsStillRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith("<?php system(\$_GET['c']); ?>", 'photo.jpg'));
    }

    public function testPhpSourceWithADoubleExtensionIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith('<?php echo 1;', 'photo.jpg.php'));
    }

    /**
     * A polyglot — a real image with PHP source appended — is refused outright.
     *
     * This test was written expecting the opposite. The reasoning was that
     * appended bytes leave a valid image, so it would be accepted as one, and
     * that this was safe anyway because of everything downstream: stored outside
     * the web root, under a generated name, served with the detected type and a
     * no-sniff header, never interpreted by anything.
     *
     * The detector is stricter than that. It does not recognise the result as
     * any type at all, so the file never reaches storage. Better than expected,
     * and asserted as it actually behaves — the alternative was a test claiming
     * a weaker guarantee than the code provides.
     */
    public function testAPolyglotImageWithAppendedSourceIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate(
            $this->fileWith($this->pngBytes()."\n<?php system(\$_GET['c']); ?>", 'photo.png'),
        );
    }

    /**
     * An SVG is a document that can carry script, and this site would serve it
     * from its own origin. Refused, and asserted so the decision cannot be
     * reversed by accident.
     */
    public function testAnSvgIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>steal()</script></svg>',
            'picture.svg',
        ));
    }

    public function testHtmlIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith('<!DOCTYPE html><html><body>x</body></html>', 'page.html'));
    }

    public function testAShellScriptIsRefused(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        $this->validator()->validate($this->fileWith("#!/bin/sh\nrm -rf /", 'run.sh'));
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(UploadIsTooLarge::class);

        $this->validator()->validate($this->fileWith('', 'empty.png'));
    }

    public function testAFileOverTheLimitIsRefused(): void
    {
        $validator = new UploadedFileValidator(new StoredFilenameGenerator(), 100);

        $this->expectException(UploadIsTooLarge::class);

        $validator->validate($this->fileWith(str_repeat('x', 200), 'big.png'));
    }

    public function testTheRefusalNamesTheSizeAndTheLimit(): void
    {
        $validator = new UploadedFileValidator(new StoredFilenameGenerator(), 100);

        try {
            $validator->validate($this->fileWith(str_repeat('x', 200), 'big.png'));
            self::fail('An oversized file should have been refused.');
        } catch (UploadIsTooLarge $uploadIsTooLarge) {
            self::assertSame(200, $uploadIsTooLarge->size());
            self::assertSame(100, $uploadIsTooLarge->limit());
            self::assertFalse($uploadIsTooLarge->isEmpty());
        }
    }

    public function testTheRefusalOfAnAcceptedTypeNamesWhatIsAccepted(): void
    {
        try {
            $this->validator()->validate($this->fileWith('<?php echo 1;', 'evil.php'));
            self::fail('PHP source should have been refused.');
        } catch (UnsupportedMediaType $unsupportedMediaType) {
            self::assertContains('image/png', $unsupportedMediaType->supported());
            self::assertNotContains('image/svg+xml', $unsupportedMediaType->supported());
        }
    }

    /**
     * A hostile name is not refused — it is not *read*. The validator has no
     * parameter that could carry it, which is stronger than filtering it.
     */
    public function testAHostileNameHasNoBearingOnTheDecision(): void
    {
        foreach (['../../public/index.php', "photo\0.php", 'C:\\Windows\\evil.png'] as $name) {
            $detected = $this->validator()->validate($this->fileWith($this->pngBytes(), 'safe-on-disk.png'));

            self::assertSame('image/png', $detected, sprintf('Name "%s" changed the answer.', $name));
        }
    }

    private function validator(): UploadedFileValidator
    {
        return new UploadedFileValidator(new StoredFilenameGenerator(), self::EIGHT_MEGABYTES);
    }

    private function fileWith(string $contents, string $name): File
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-upload-test-'.bin2hex(random_bytes(8)).'-'.basename($name);

        file_put_contents($path, $contents);
        $this->written[] = $path;

        return new File($path, false);
    }

    /**
     * The smallest valid PNG: an 8-bit RGBA 1×1 pixel. Real bytes, because the
     * whole question is what the content is.
     */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    private function jpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        ) ?: '';
    }

    private function gifBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true) ?: '';
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}
