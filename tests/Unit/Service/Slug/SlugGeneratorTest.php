<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Slug;

use App\Service\Slug\SlugGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Address generation, which is where the interesting failures live: accents,
 * punctuation, script the transliterator cannot render, and titles that reduce
 * to nothing at all.
 *
 * No database is involved, which is the point of keeping this service pure —
 * these cases are cheap enough to cover exhaustively.
 */
final class SlugGeneratorTest extends TestCase
{
    /**
     * FR-009 in machine-readable form. Lowercase letters, digits and single
     * hyphens; no leading or trailing hyphen; never empty.
     */
    private const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exactProvider(): iterable
    {
        yield 'punctuation is dropped' => ['Hello, World!', 'hello-world'];
        yield 'case is folded' => ['HELLO World', 'hello-world'];
        yield 'runs of whitespace collapse' => ['  Multiple   spaces  ', 'multiple-spaces'];
        yield 'digits survive' => ['Symfony 8.1 release', 'symfony-8-1-release'];
        yield 'accents are transliterated' => ['Ärger mit Ümlauten', 'arger-mit-umlauten'];
        yield 'Slovak diacritics are transliterated' => ['Žltý kôň', 'zlty-kon'];
        yield 'an already valid slug is unchanged' => ['hello-world', 'hello-world'];
        yield 'leading and trailing punctuation is trimmed' => ['---Hello---', 'hello'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableTitleProvider(): iterable
    {
        yield 'only punctuation' => ['!!!'];
        yield 'only whitespace' => ['   '];
        yield 'empty' => [''];
        yield 'only hyphens' => ['---'];
        yield 'only symbols' => ['€ £ ¥'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardTitleProvider(): iterable
    {
        yield 'Japanese' => ['日本語のタイトル'];
        yield 'Cyrillic' => ['Привет мир'];
        yield 'Greek' => ['Καλημέρα κόσμε'];
        yield 'emoji' => ['Release 🎉 party'];
        yield 'mixed scripts and punctuation' => ['C# & .NET — a primer'];
        yield 'a very long title' => [str_repeat('Very long title ', 40)];
    }

    #[DataProvider('exactProvider')]
    public function testItProducesTheExpectedSlug(string $title, string $expected): void
    {
        self::assertSame($expected, new SlugGenerator()->generate($title));
    }

    /**
     * Every title this test class knows about, whatever its shape.
     *
     * @return iterable<string, array{string}>
     */
    public static function everyTitleProvider(): iterable
    {
        foreach (self::exactProvider() as $name => [$title]) {
            yield $name => [$title];
        }

        yield from self::awkwardTitleProvider();
        yield from self::unusableTitleProvider();
    }

    #[DataProvider('everyTitleProvider')]
    public function testEverySlugIsUrlSafe(string $title): void
    {
        self::assertMatchesRegularExpression(
            self::SLUG_PATTERN,
            new SlugGenerator()->generate($title),
        );
    }

    /**
     * A draft must always be saveable. A title that yields nothing usable falls
     * back to a generated token rather than being refused.
     */
    #[DataProvider('unusableTitleProvider')]
    public function testAnUnusableTitleStillProducesASlug(string $title): void
    {
        self::assertNotSame('', new SlugGenerator()->generate($title));
    }

    public function testFallbackSlugsDoNotCollideWithEachOther(): void
    {
        $generator = new SlugGenerator();

        $slugs = [];
        for ($i = 0; $i < 50; ++$i) {
            $slugs[] = $generator->generate('!!!');
        }

        self::assertCount(50, array_unique($slugs), 'Fallback slugs must be distinct.');
    }

    public function testTheSameTitleAlwaysProducesTheSameSlug(): void
    {
        $generator = new SlugGenerator();

        self::assertSame(
            $generator->generate('Hello, World!'),
            $generator->generate('Hello, World!'),
            'Generation is deterministic except for the unusable-title fallback.',
        );
    }
}
