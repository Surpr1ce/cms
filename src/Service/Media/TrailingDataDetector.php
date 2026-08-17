<?php

declare(strict_types=1);

namespace App\Service\Media;

use function file_get_contents;
use function is_int;
use function str_ends_with;
use function strlen;
use function substr;
use function unpack;

/**
 * Whether a file carries bytes past the end of the thing it claims to be.
 *
 * A PNG with PHP source appended is still a valid PNG: every decoder reads to
 * the `IEND` chunk and stops, so the extra bytes ride along invisibly. That is
 * the classic polyglot, and this refuses it.
 *
 * **Why this exists at all**, given feature 005 already makes such a file
 * harmless — stored outside the web root, under a generated name, served with
 * the recorded type and `nosniff`, interpreted by nothing:
 *
 * Because the answer was not the same on two machines. `finfo` refused the
 * polyglot on the development machine and accepted it in CI, and the test that
 * asserted the refusal had been written to match whichever had run last. A
 * security check whose result depends on the version of libmagic installed is
 * not a check; it is a coincidence with a test around it. This makes the rule
 * explicit and the answer the same everywhere.
 *
 * The rule is also worth having on its own terms. Nothing legitimate uploads an
 * image with a payload stapled to the end of it, and defence that does not
 * depend on every downstream layer staying correct is cheaper than defence that
 * does.
 */
final readonly class TrailingDataDetector
{
    /**
     * How much of the end of a file is enough to find its terminator.
     *
     * A JPEG may carry padding after its end marker in the wild, and reading a
     * fixed tail keeps this cheap on an eight-megabyte upload.
     */
    private const int TAIL = 32;

    public function hasTrailingData(string $path, string $detectedType): bool
    {
        $bytes = file_get_contents($path);

        if (false === $bytes || '' === $bytes) {
            return false;
        }

        return match ($detectedType) {
            'image/png' => !$this->endsWith($bytes, 'IEND', 4),
            'image/jpeg' => !$this->endsWith($bytes, "\xFF\xD9", 0),
            'image/gif' => !str_ends_with($bytes, "\x3B"),
            'image/webp' => $this->webPCarriesMoreThanItDeclares($bytes),
            'application/pdf' => !$this->endsWith($bytes, '%%EOF', self::TAIL),
            // AVIF is a box format with no terminator to check: its length is
            // declared per box, and reading that properly is a parser rather
            // than a guard. Left alone rather than half-checked.
            default => false,
        };
    }

    /**
     * Whether the marker appears within the last few bytes.
     *
     * `$slack` allows for what legitimately follows a terminator — a PNG's
     * four-byte checksum, the newline a PDF writer leaves behind — without
     * allowing room for a payload.
     */
    private function endsWith(string $bytes, string $marker, int $slack): bool
    {
        $window = substr($bytes, -(strlen($marker) + $slack));

        return str_contains($window, $marker);
    }

    /**
     * A WebP file declares its own length in the RIFF header, which makes this
     * the one format here that can be checked exactly rather than by terminator.
     */
    private function webPCarriesMoreThanItDeclares(string $bytes): bool
    {
        if (strlen($bytes) < 12) {
            return false;
        }

        $header = unpack('Vsize', substr($bytes, 4, 4));

        if (false === $header || !is_int($header['size'] ?? null)) {
            return false;
        }

        // The declared size counts everything after the first eight bytes.
        return strlen($bytes) > $header['size'] + 8;
    }
}
