<?php

declare(strict_types=1);

namespace App\Service\Media;

use function array_column;
use function implode;

/**
 * The sizes this site serves images at.
 *
 * Named rather than numeric, and an enum rather than a configuration array, for
 * two reasons that are really one.
 *
 * A template asks for `thumbnail`, not for `240`. Changing what a thumbnail
 * means is then one edit here rather than a search through every template that
 * happened to use the number — and a template cannot ask for a size that does
 * not exist, because there is nothing to type.
 *
 * The same enum is what the route accepts, so "a size nobody defined" is refused
 * by routing before any code runs. A dimension a reader can name is a dimension
 * a reader can ask the server to generate, ten thousand times, at ten thousand
 * different sizes.
 */
enum ImageSize: string
{
    /**
     * A listing card. Small enough that a page of twelve costs less than one
     * original.
     */
    case Thumbnail = 'thumbnail';

    /**
     * Inside an article's body, in a column that is not the full width of the
     * page.
     */
    case Medium = 'medium';

    /**
     * The lead image of an article, at the full width of the reading column and
     * doubled for a dense display.
     */
    case Large = 'large';

    /**
     * The longest side, in pixels. An image fits *within* a square of this — the
     * proportions decide which side is the long one, and nothing is cropped.
     */
    public function longestSide(): int
    {
        return match ($this) {
            self::Thumbnail => 400,
            self::Medium => 800,
            self::Large => 1600,
        };
    }

    /**
     * The pattern the route accepts, built from the cases themselves so that
     * adding a size cannot leave the route behind.
     */
    public static function routePattern(): string
    {
        return implode('|', array_column(self::cases(), 'value'));
    }
}
