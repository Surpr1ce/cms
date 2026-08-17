<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditAction;
use App\Entity\AuditEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

use function mb_substr;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * Writes the record.
 *
 * Called from the services where a decision has a name — publishing, deleting,
 * granting — rather than from a Doctrine lifecycle listener. A listener would
 * catch every write automatically, which sounds better and is worse: it would
 * know neither what a change *meant* nor who made it, and it would record a typo
 * correction with the same weight as an account being given administrator's
 * permissions.
 *
 * **Recording cannot undo what it records.** FR-007, and the reason for the
 * `try` below: if writing the entry fails, the article is still published, and
 * the failure goes to the application log rather than to the person who
 * published it. A log that can roll back the thing it is logging is a liability
 * pretending to be a safeguard.
 *
 * Who acted is read from the session here rather than passed in. Every caller
 * would otherwise have to thread the current person through, and a caller that
 * forgot would record an action with nobody attached — which is the one value
 * this class reserves for meaning "there genuinely was nobody".
 */
final readonly class AuditLog
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $subject what was acted on, described so it still reads after
     *                        the thing itself is gone
     */
    public function record(AuditAction $action, string $subject): void
    {
        try {
            $actor = $this->security->getUser();
            $actor = $actor instanceof User ? $actor : null;

            $this->entityManager->persist(new AuditEntry(
                $action,
                // Truncated rather than refused. An article title can be two
                // hundred characters and a log entry is not the place to
                // discover a column limit — losing the tail of a title is a
                // smaller failure than losing the entry.
                mb_substr($subject, 0, 255),
                $actor,
                // A console command has no signed-in person, and recording that
                // honestly is better than attributing it to somebody.
                $actor?->getEmail() ?? '',
                $this->clock->now(),
            ));

            $this->entityManager->flush();
        } catch (Throwable $throwable) {
            // Deliberately swallowed. See the class comment: the action has
            // already happened, and refusing it now because a record could not
            // be written would be the worse of the two outcomes.
            $this->logger->error('An audit entry could not be written.', [
                'action' => $action->value,
                'subject' => $subject,
                'reason' => $throwable->getMessage(),
            ]);
        }
    }
}
