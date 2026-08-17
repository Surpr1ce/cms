<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\TrailingDataDetector;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function file_put_contents;
use function is_file;
use function pack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function random_bytes;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

/**
 * Whether a file carries a payload past the end of the thing it claims to be.
 *
 * This exists because the answer used to depend on which machine asked. `finfo`
 * refused a PNG with PHP appended on the development machine and accepted it in
 * CI, and the test asserting the behaviour had been written to match whichever
 * had run last. The rule is explicit now, and these are the cases that keep it
 * from drifting back into a coincidence.
 *
 * Both halves are tested for every format: a clean file must be accepted, and an
 * extended one refused. A detector that refused everything would pass half of
 * this file and break every upload.
 */
final class TrailingDataDetectorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $written = [];

    private TrailingDataDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new TrailingDataDetector();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[DataProvider('cleanFiles')]
    public function testAFileThatEndsWhereItShouldIsAccepted(string $bytes, string $type): void
    {
        self::assertFalse($this->detector->hasTrailingData($this->write($bytes), $type));
    }

    /**
     * The appended bytes are deliberately not PHP source.
     *
     * The rule is about bytes past the end of the file, not about what those
     * bytes say — and writing a literal web shell to disk here would test the
     * development machine's antivirus rather than this class. It does exactly
     * that: on Windows, Defender locks a temporary file containing
     * `<?php system(...)` so that even reading it back fails, which is what made
     * `finfo` report "no type" locally and accept the same file in CI. That
     * disagreement is the whole reason this class exists, and reproducing it
     * inside its own tests would be a poor joke.
     *
     * A shell is still uploaded, in
     * {@see \App\Tests\Functional\Admin\UploadsCannotExecuteTest}, where what is
     * asserted is that nothing is catalogued and nothing is written — an
     * assertion that holds whichever reason the refusal had.
     */
    #[DataProvider('cleanFiles')]
    public function testTheSameFileWithAPayloadAppendedIsRefused(string $bytes, string $type): void
    {
        $payload = "\n".str_repeat('PAYLOAD', 8);

        self::assertTrue($this->detector->hasTrailingData($this->write($bytes.$payload), $type));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cleanFiles(): iterable
    {
        yield 'png' => [self::png(), 'image/png'];
        yield 'jpeg' => [self::jpeg(), 'image/jpeg'];
        yield 'gif' => [self::gif(), 'image/gif'];
        yield 'webp' => [self::webp(), 'image/webp'];
        yield 'pdf' => [self::pdf(), 'application/pdf'];
    }

    /**
     * AVIF is a box format with no terminator, so it is left alone rather than
     * half-checked. Asserted so that the gap is a decision on the record rather
     * than something nobody noticed.
     */
    public function testAFormatWithNoTerminatorIsNotGuessedAt(): void
    {
        self::assertFalse(
            $this->detector->hasTrailingData($this->write(str_repeat('x', 64)), 'image/avif'),
        );
    }

    public function testAnEmptyFileIsNotTreatedAsCarryingAnything(): void
    {
        self::assertFalse($this->detector->hasTrailingData($this->write(''), 'image/png'));
    }

    /**
     * A checksum is what legitimately follows a PNG's end marker, and refusing
     * it would refuse every PNG ever written.
     */
    public function testAPngsOwnChecksumIsNotMistakenForAPayload(): void
    {
        self::assertFalse($this->detector->hasTrailingData($this->write(self::png()), 'image/png'));
    }

    private function write(string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-trailing-'.bin2hex(random_bytes(8));

        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    private static function png(): string
    {
        // Signature, a minimal header chunk, then the IEND chunk with its
        // four-byte checksum — which is exactly what may follow the marker.
        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13).'IHDR'.str_repeat("\x00", 13).pack('N', 0)
            .pack('N', 0)."IEND\xAE\x42\x60\x82";
    }

    private static function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0".str_repeat("\x00", 16)."\xFF\xD9";
    }

    private static function gif(): string
    {
        return 'GIF89a'.str_repeat("\x00", 16)."\x3B";
    }

    private static function webp(): string
    {
        $payload = 'WEBPVP8 '.str_repeat("\x00", 16);

        return 'RIFF'.pack('V', strlen($payload)).$payload;
    }

    private static function pdf(): string
    {
        return "%PDF-1.7\n".str_repeat('x', 32)."\ntrailer\n%%EOF\n";
    }
}
