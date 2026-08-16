<?php

declare(strict_types=1);

namespace App\Service\Slug;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Turns a title into a URL-safe address.
 *
 * No database and no container state, so the interesting cases — accents,
 * punctuation, non-Latin script, titles that reduce to nothing — are cheap
 * enough to test exhaustively. Uniqueness is somebody else's job; see
 * UniqueSlugGenerator.
 *
 * Output always satisfies /^[a-z0-9]+(?:-[a-z0-9]+)*$/, which is FR-009 of
 * specs/001-core-content-model in machine-readable form.
 */
final readonly class SlugGenerator
{
    /**
     * Length in bytes of the fallback token. Eight hexadecimal characters is
     * short enough to stay readable in a URL and wide enough that two drafts
     * saved in the same second do not collide in practice.
     */
    private const int FALLBACK_BYTES = 4;

    private AsciiSlugger $slugger;

    public function __construct()
    {
        $this->slugger = new AsciiSlugger();
    }

    public function generate(string $title): string
    {
        $slug = $this->slugger->slug($title)->lower()->toString();

        // AsciiSlugger already collapses separators, but a title that mixes
        // punctuation and spaces can still leave doubled or edge hyphens behind.
        $slug = preg_replace('/-{2,}/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        // A title made entirely of punctuation, or of script the transliterator
        // cannot render, leaves nothing usable. A draft must still be saveable,
        // so fall back to a generated token rather than refusing.
        if ('' === $slug) {
            return bin2hex(random_bytes(self::FALLBACK_BYTES));
        }

        return $slug;
    }
}
