<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\AuditAction;
use App\Entity\AuditEntry;
use App\Entity\User;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AuditEntry>
 */
final class AuditEntryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AuditEntry::class;
    }

    /**
     * An entry whose account has gone.
     *
     * The state the log exists for: `actor` is severed when an account is
     * deleted and `actorLabel` is what still answers "who did this". A factory
     * that could only make the easy case would let a test claim the hard one.
     */
    public function byADepartedAccount(string $email = 'departed@example.com'): static
    {
        return $this->with(['actor' => null, 'actorLabel' => $email]);
    }

    /**
     * An entry made by a particular account.
     *
     * Both fields at once, and the only supported way to choose the actor.
     * Setting `actor` alone would leave the label naming somebody else — a
     * fixture in a state `AuditLog` cannot produce, and therefore a fixture that
     * can make a test pass against behaviour that does not exist.
     */
    public function by(User $account): static
    {
        return $this->with(['actor' => $account, 'actorLabel' => $account->getEmail()]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // Created eagerly rather than left as a factory, so the label below can
        // be read from the same account the entry points at. Foundry resolves
        // nested factories after the constructor has already run.
        $actor = UserFactory::createOne();

        return [
            'action' => self::faker()->randomElement(AuditAction::cases()),
            'subject' => self::faker()->sentence(4),
            'actor' => $actor,
            'actorLabel' => $actor->getEmail(),
            'occurredAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 month')),
        ];
    }
}
