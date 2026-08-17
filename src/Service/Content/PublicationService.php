<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\AuditAction;
use App\Entity\PublishableContent;
use App\Exception\DomainException;
use App\Service\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Clock\ClockInterface;

/**
 * The four transitions, driven from a screen.
 *
 * It adds no rule. Every refusal here comes from the entity — the state machine
 * and the "no title, no body" check both live in PublishableContent — and this
 * only supplies the clock and the flush. That is deliberate: a service that
 * re-implemented the transitions would be a second opinion about what publishing
 * means, and two opinions disagree eventually.
 *
 * Who may do it is not asked here either. That is the controller's question, put
 * to ArticleVoter or PageVoter before it calls.
 */
final readonly class PublicationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws DomainException when the content refuses the transition
     */
    public function publish(PublishableContent $content): void
    {
        $content->publish($this->clock->now());
        $this->entityManager->flush();

        // Recorded after the flush, never before. A refused transition throws
        // above and reaches nothing here, which is FR-006: an action that did
        // not happen is not recorded.
        $this->audit->record(AuditAction::ContentPublished, $content->getTitle());
    }

    /**
     * @throws DomainException
     */
    public function unpublish(PublishableContent $content): void
    {
        $content->unpublish();
        $this->entityManager->flush();

        $this->audit->record(AuditAction::ContentUnpublished, $content->getTitle());
    }

    /**
     * @throws DomainException
     */
    public function archive(PublishableContent $content): void
    {
        $content->archive();
        $this->entityManager->flush();

        $this->audit->record(AuditAction::ContentArchived, $content->getTitle());
    }

    /**
     * @throws DomainException
     */
    public function restore(PublishableContent $content): void
    {
        $content->restore();
        $this->entityManager->flush();

        $this->audit->record(AuditAction::ContentRestored, $content->getTitle());
    }

    /**
     * Dispatch by name, for a single screen control that carries the transition
     * it wants.
     *
     * The list is closed: an unrecognised name is refused rather than ignored,
     * so a form field a person edited cannot reach anything that was not offered.
     *
     * @throws DomainException
     */
    public function apply(string $transition, PublishableContent $content): void
    {
        match ($transition) {
            'publish' => $this->publish($content),
            'unpublish' => $this->unpublish($content),
            'archive' => $this->archive($content),
            'restore' => $this->restore($content),
            default => throw new InvalidArgumentException(sprintf('Unknown transition "%s".', $transition)),
        };
    }
}
