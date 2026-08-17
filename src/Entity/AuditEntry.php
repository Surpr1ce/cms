<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditEntryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing somebody decided.
 *
 * Everything about this class is shaped by one requirement: **an entry has to
 * outlive what it is about.** A log that goes blank when an article is deleted is
 * a log about nothing, and the moment somebody most wants to read it is exactly
 * the moment after something disappeared.
 *
 * So the subject is a description in text, not a relation. And the person is
 * kept twice — as a relation for the ordinary case, and as their address in text
 * for after the account is gone. `ON DELETE SET NULL` severs the first; the
 * second is what still answers "who did this".
 *
 * There are no setters and there is no way to change or remove an entry through
 * this application. That is not an oversight to be tidied up later: a record the
 * application can rewrite is a record that proves nothing.
 */
#[ORM\Entity(repositoryClass: AuditEntryRepository::class)]
#[ORM\Index(name: 'idx_audit_entry_occurred_at', fields: ['occurredAt'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 40, enumType: AuditAction::class)]
        private readonly AuditAction $action,
        /**
         * What was acted on, described well enough to recognise: a title, a name,
         * an address. Never an identifier on its own, because an identifier for
         * a row that no longer exists is not information.
         */
        #[ORM\Column(length: 255)]
        private readonly string $subject,
        /**
         * The account, while it exists. `SET NULL` rather than `CASCADE`: a
         * deleted account must not take its history with it, which is the whole
         * point of also keeping the address below.
         */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private readonly ?User $actor,
        /**
         * Who it was, in text, forever.
         *
         * Empty when there was nobody — a console command has no signed-in
         * person, and recording that honestly is better than attributing it to
         * whoever happens to be first in the table.
         */
        #[ORM\Column(length: 180)]
        private readonly string $actorLabel,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * Who acted, as it will always read.
     *
     * Falls back to a phrase rather than to an empty cell, so a reader is told
     * that nobody was signed in instead of being left to wonder whether the
     * field failed to save.
     */
    public function getActorLabel(): string
    {
        return '' === $this->actorLabel ? 'the system' : $this->actorLabel;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
