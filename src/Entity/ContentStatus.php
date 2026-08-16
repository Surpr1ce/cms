<?php

declare(strict_types=1);

namespace App\Entity;

use function in_array;

/**
 * The three states any piece of content can be in.
 *
 * Stored as a string rather than as a PostgreSQL native enum: Doctrine does not
 * model native enum types, so a native column produces a migration that fights
 * doctrine:migrations:diff on every subsequent run.
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * The states this one may move to, and nothing else.
     *
     * Archived leads only back to Draft. Bringing content back and making it
     * visible again are two decisions, and whoever makes the first has not
     * necessarily made the second.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Human-readable name, for the admin screens a later feature will add.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }
}
