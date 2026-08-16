<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ContentStatus;

use function in_array;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * The state machine, tested exhaustively rather than by example.
 *
 * Every one of the nine ordered pairs is asserted, so a transition added or
 * removed by accident fails here rather than surfacing as content that will not
 * publish months later.
 */
final class ContentStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{ContentStatus, ContentStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        $expected = [
            'draft' => ['draft' => false, 'published' => true, 'archived' => true],
            'published' => ['draft' => true, 'published' => false, 'archived' => true],
            'archived' => ['draft' => true, 'published' => false, 'archived' => false],
        ];

        foreach (ContentStatus::cases() as $from) {
            foreach (ContentStatus::cases() as $to) {
                yield sprintf('%s to %s', $from->value, $to->value) => [
                    $from,
                    $to,
                    $expected[$from->value][$to->value],
                ];
            }
        }
    }

    #[DataProvider('transitionProvider')]
    public function testItKnowsWhichTransitionsAreAllowed(
        ContentStatus $from,
        ContentStatus $to,
        bool $allowed,
    ): void {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    public function testItRefusesToTransitionToItself(): void
    {
        foreach (ContentStatus::cases() as $status) {
            self::assertFalse(
                $status->canTransitionTo($status),
                sprintf('Status "%s" should not transition to itself.', $status->value),
            );
        }
    }

    public function testArchivedContentReturnsToDraftAndNotStraightToPublished(): void
    {
        self::assertSame([ContentStatus::Draft], ContentStatus::Archived->allowedTransitions());
    }

    public function testAllowedTransitionsAndCanTransitionToAgree(): void
    {
        foreach (ContentStatus::cases() as $from) {
            foreach (ContentStatus::cases() as $to) {
                self::assertSame(
                    in_array($to, $from->allowedTransitions(), true),
                    $from->canTransitionTo($to),
                    sprintf('Disagreement for %s to %s.', $from->value, $to->value),
                );
            }
        }
    }

    public function testEveryStatusHasALabel(): void
    {
        foreach (ContentStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }

    public function testStoredValuesAreStableBecauseTheSchemaDependsOnThem(): void
    {
        self::assertSame('draft', ContentStatus::Draft->value);
        self::assertSame('published', ContentStatus::Published->value);
        self::assertSame('archived', ContentStatus::Archived->value);
    }
}
