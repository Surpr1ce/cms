<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\PasswordResetRequest;

use function bin2hex;

use DateTimeImmutable;

use function hash;
use function random_bytes;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PasswordResetRequest>
 */
final class PasswordResetRequestFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PasswordResetRequest::class;
    }

    /**
     * A request old enough to have expired.
     *
     * Two hours rather than one and a minute, so that the test does not depend
     * on how long the suite takes to reach it.
     */
    public function expired(): static
    {
        return $this->with(['requestedAt' => new DateTimeImmutable('-2 hours')]);
    }

    public function alreadyUsed(): static
    {
        return $this->afterInstantiate(static function (PasswordResetRequest $request): void {
            $request->consume();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'account' => UserFactory::new(),
            // A hash of something, so that a factory-made request is never
            // openable by a token any test could guess — a fixture that happened
            // to be openable would make an assertion about refusal pass for the
            // wrong reason.
            'tokenHash' => hash('sha256', bin2hex(random_bytes(16))),
            'requestedAt' => new DateTimeImmutable(),
        ];
    }
}
