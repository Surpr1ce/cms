<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Exception\UnsupportedMediaType;

/**
 * Produces the name a file is stored under.
 *
 * It takes the detected type and nothing else. The name the client supplied is
 * not a parameter, so it cannot influence the result — which makes path
 * traversal and executable extensions unreachable rather than filtered out. A
 * filter is only as good as its last review; an input that is never read needs
 * no review at all.
 *
 * The type is decided by an allow-list. Naming what is permitted, rather than
 * what is forbidden, is what keeps an unanticipated type from being accepted by
 * default.
 */
final class StoredFilenameGenerator
{
    /**
     * Detected MIME type to the extension the file is stored under.
     *
     * SVG is deliberately absent: it is a document that can carry script, and
     * accepting it would mean serving attacker-controlled markup from this
     * site's own origin. Adding it later is a decision with its own reasoning,
     * not an oversight to be corrected.
     */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
    ];

    /**
     * Sixteen random bytes: wide enough that a collision is not a thing that
     * happens, and the unique index on media.filename catches it if it somehow
     * does.
     */
    private const int RANDOM_BYTES = 16;

    /**
     * @throws UnsupportedMediaType
     */
    public function generate(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));

        if (!isset(self::EXTENSIONS[$mimeType])) {
            throw UnsupportedMediaType::forType($mimeType, self::supportedTypes());
        }

        return bin2hex(random_bytes(self::RANDOM_BYTES)).'.'.self::EXTENSIONS[$mimeType];
    }

    public function supports(string $mimeType): bool
    {
        return isset(self::EXTENSIONS[strtolower(trim($mimeType))]);
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::EXTENSIONS);
    }
}
