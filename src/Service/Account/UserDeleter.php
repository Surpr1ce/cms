<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\AuditAction;
use App\Entity\User;
use App\Exception\UserStillOwnsContent;
use App\Repository\ArticleRepository;
use App\Repository\MediaRepository;
use App\Service\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes an account, or explains what is standing in the way.
 *
 * Ownership covers articles authored and files uploaded, in every status.
 * Archiving is not a release of ownership — an archived article still has an
 * author, still appears in the administration area, and would still be left
 * pointing at nothing if the account went.
 *
 * Pages do not count, because a page has no author. That is not an oversight;
 * it is why Page is a separate entity.
 *
 * The rule is also in the schema, as ON DELETE RESTRICT on article.author_id and
 * media.uploaded_by_id. What this adds is the count, so an administration screen
 * can say "12 articles and 3 files" rather than reporting a foreign-key name.
 */
final readonly class UserDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private MediaRepository $media,
        private AuditLog $audit,
    ) {
    }

    /**
     * @throws UserStillOwnsContent
     */
    public function delete(User $user): void
    {
        $articleCount = $this->articles->countByAuthor($user);
        $mediaCount = $this->media->countUploadedBy($user);

        if ($articleCount > 0 || $mediaCount > 0) {
            throw UserStillOwnsContent::with($user->getEmail(), $articleCount, $mediaCount);
        }

        // Read before the row goes, because afterwards there is nothing to read
        // it from — which is the whole reason an entry keeps a description in
        // text rather than a reference.
        $email = $user->getEmail();

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->audit->record(AuditAction::AccountDeleted, $email);
    }

    /**
     * Whether the account can be removed, without attempting it.
     *
     * An administration screen needs this to decide whether to offer the action
     * at all — offering a button that always fails is worse than not offering it.
     */
    public function canBeDeleted(User $user): bool
    {
        return 0 === $this->articles->countByAuthor($user)
            && 0 === $this->media->countUploadedBy($user);
    }
}
