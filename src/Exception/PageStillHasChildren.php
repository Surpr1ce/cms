<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * Deletion was attempted on a page that still has pages nested underneath it.
 *
 * Pages are treated more strictly here than categories, which re-parent their
 * children. Page nesting is also the menu structure, and silently rearranging a
 * visitor's navigation is a worse outcome than refusing the deletion.
 */
final class PageStillHasChildren extends DomainException
{
    private function __construct(
        private readonly string $pageTitle,
        private readonly int $childCount,
    ) {
        parent::__construct(sprintf(
            'The page "%s" still has %d child page(s) and cannot be deleted.',
            $pageTitle,
            $childCount,
        ));
    }

    public static function with(string $pageTitle, int $childCount): self
    {
        return new self($pageTitle, $childCount);
    }

    public function pageTitle(): string
    {
        return $this->pageTitle;
    }

    public function childCount(): int
    {
        return $this->childCount;
    }
}
