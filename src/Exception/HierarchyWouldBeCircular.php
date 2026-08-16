<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * A category or page was about to become its own ancestor.
 *
 * Left unchecked this is an infinite loop the first time a template renders
 * breadcrumbs or a menu, at which point the cause is a long way from the symptom.
 */
final class HierarchyWouldBeCircular extends DomainException
{
    private function __construct(
        private readonly string $entityType,
        private readonly string $label,
    ) {
        parent::__construct(sprintf(
            'The %s "%s" cannot be placed under itself or under one of its own descendants.',
            $entityType,
            $label,
        ));
    }

    public static function forCategory(string $name): self
    {
        return new self('category', $name);
    }

    public static function forPage(string $title): self
    {
        return new self('page', $title);
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function label(): string
    {
        return $this->label;
    }
}
