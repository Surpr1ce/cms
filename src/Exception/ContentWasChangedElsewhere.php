<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Somebody else changed this content between the form being opened and it being
 * saved.
 *
 * The save is refused rather than applied, and refused rather than merged.
 * Merging two versions of prose automatically is a guess dressed up as a
 * feature: the result belongs to nobody and neither editor is told what happened
 * to their sentences. Refusing costs the second editor a reload and costs the
 * first editor nothing, which is the right way round — before this rule existed,
 * the first editor's work simply vanished and the second was told "Saved."
 *
 * Both versions are carried as typed values so that a caller can say something
 * more specific than the message if it ever wants to, and so a test can assert
 * on the rule that was broken rather than on a sentence.
 */
final class ContentWasChangedElsewhere extends DomainException
{
    private function __construct(
        private readonly ?int $submittedVersion,
        private readonly int $storedVersion,
    ) {
        parent::__construct(
            'Somebody else changed this content while you were editing it. '
            .'Nothing you submitted has been saved. Open it again to see their '
            .'version, then reapply your changes.',
        );
    }

    /**
     * @param int|null $submittedVersion null when the submission carried none at
     *                                   all, which is refused for the same reason a wrong one is: a version
     *                                   that travelled through a browser is under somebody else's control
     */
    public static function between(?int $submittedVersion, int $storedVersion): self
    {
        return new self($submittedVersion, $storedVersion);
    }

    public function submittedVersion(): ?int
    {
        return $this->submittedVersion;
    }

    public function storedVersion(): int
    {
        return $this->storedVersion;
    }
}
