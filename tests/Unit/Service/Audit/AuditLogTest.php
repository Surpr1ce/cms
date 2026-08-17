<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Audit;

use App\Entity\AuditAction;
use App\Entity\AuditEntry;
use App\Entity\User;
use App\Service\Audit\AuditLog;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function mb_strlen;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

use function str_repeat;

use Stringable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

/**
 * The three things that happen at the moment a record is written, two of which
 * are failures and neither of which a functional test can arrange.
 *
 * **A failure to record never undoes what was recorded.** FR-007. The article is
 * already published by the time this class is called, so refusing it now because
 * a row could not be written would be the worse of the two outcomes — a log that
 * can roll back the thing it is logging is a liability pretending to be a
 * safeguard. The throw is swallowed and the reason goes to the application log,
 * and the assertion is about the log entry rather than about the absence of an
 * exception: "it did not throw" would also pass if the failure were dropped
 * silently, which is the version of this that is genuinely dangerous.
 *
 * **An action with nobody signed in records nobody**, honestly, rather than being
 * attributed to somebody. That value means "there genuinely was nobody" — which
 * is what a console command produces — and it is the reason the actor is read
 * from the session here rather than threaded through every caller, where one
 * caller forgetting would make a mistake and a truth indistinguishable.
 *
 * **A long subject is truncated rather than refused**, because a log entry is not
 * the place to discover a column limit.
 */
final class AuditLogTest extends TestCase
{
    public function testItRecordsWhatWasDoneAndWhoDidIt(): void
    {
        $actor = new User('editor@example.com', 'Erin Editor', new DateTimeImmutable());
        $persisted = [];

        $this->logWith($actor, $persisted)->record(AuditAction::ContentPublished, 'A headline');

        $entry = $persisted[0] ?? null;

        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertSame(AuditAction::ContentPublished, $entry->getAction());
        self::assertSame('A headline', $entry->getSubject());
        self::assertSame($actor, $entry->getActor());
        self::assertSame('editor@example.com', $entry->getActorLabel());
    }

    /**
     * A console command has nobody signed in. The entry still exists and says so,
     * rather than naming somebody who was not there.
     */
    public function testAnActionWithNobodySignedInRecordsNobody(): void
    {
        $persisted = [];

        $this->logWith(null, $persisted)->record(AuditAction::AccountCreated, 'someone@example.com');

        $entry = $persisted[0] ?? null;

        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertNull($entry->getActor());
        // The log stores an empty label and the entity reads it back as a
        // phrase, so a reader is told nobody was signed in rather than left
        // wondering whether the field failed to save.
        self::assertSame('the system', $entry->getActorLabel());
    }

    public function testALongSubjectIsTruncatedRatherThanCostingTheEntry(): void
    {
        $persisted = [];

        $this->logWith(null, $persisted)->record(AuditAction::ContentDeleted, str_repeat('a', 400));

        $entry = $persisted[0] ?? null;

        self::assertInstanceOf(AuditEntry::class, $entry);
        self::assertSame(255, mb_strlen($entry->getSubject()));
    }

    /**
     * The assertion this file exists for.
     */
    public function testAFailureToRecordIsSwallowedAndReportedToTheApplicationLog(): void
    {
        $logger = new CollectingLogger();
        $persisted = [];

        $log = $this->logWith(
            null,
            $persisted,
            $logger,
            new RuntimeException('the database went away'),
        );

        // No expectException: not throwing is the behaviour under test.
        $log->record(AuditAction::ContentPublished, 'A headline');

        self::assertCount(1, $logger->records);
        self::assertSame('An audit entry could not be written.', $logger->records[0]['message']);
        self::assertSame('content.published', $logger->records[0]['context']['action']);
        self::assertSame('A headline', $logger->records[0]['context']['subject']);
        self::assertSame('the database went away', $logger->records[0]['context']['reason']);
    }

    /**
     * @param list<object> $persisted filled with whatever the log persisted
     */
    private function logWith(
        ?User $actor,
        array &$persisted,
        ?CollectingLogger $logger = null,
        ?RuntimeException $failOnFlush = null,
    ): AuditLog {
        // A stub rather than a mock: nothing here expects a particular call, it
        // only needs somewhere for persist() to go and a flush() that can be made
        // to fail. PHPUnit says so too, and the suite fails on notices.
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );

        if ($failOnFlush instanceof RuntimeException) {
            $entityManager->method('flush')->willThrowException($failOnFlush);
        }

        // A stub of Security rather than a subclass: it is marked @final, so
        // extending it is refused by the analyser — and rightly, since the only
        // thing this class is ever asked here is who is signed in.
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);

        return new AuditLog(
            $entityManager,
            $security,
            new MockClock(new DateTimeImmutable('2026-08-17 12:00:00')),
            $logger ?? new CollectingLogger(),
        );
    }
}

/**
 * Keeps what it was told, so the test can assert on it. A mock would need the
 * message and the context described in advance, which is the thing being checked.
 */
final class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
