<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Page;
use App\Entity\User;

use function in_array;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What somebody may do to a standalone page.
 *
 * Role alone, because a page has no author (FR-022). That is not a simplification
 * — it is the reason `Page` is a separate entity rather than an article with a
 * type flag, and it means an author has no claim on any page whatsoever.
 *
 * A separate class from ArticleVoter rather than a branch inside it: one voter
 * switching on subject type would need a `supports()` that quietly abstains on
 * anything it fails to recognise, and an abstention is not a refusal.
 *
 * @extends Voter<string, Page>
 */
final class PageVoter extends Voter
{
    public const string VIEW = 'PAGE_VIEW';

    public const string EDIT = 'PAGE_EDIT';

    public const string DELETE = 'PAGE_DELETE';

    public const string PUBLISH = 'PAGE_PUBLISH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::PUBLISH], true)
            && $subject instanceof Page;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (!$subject instanceof Page) {
            return false;
        }

        if (self::VIEW === $attribute && $subject->isPublished()) {
            return true;
        }

        return $this->isEditorial($user);
    }

    private function isEditorial(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(User::ROLE_EDITOR, $roles, true)
            || in_array(User::ROLE_ADMIN, $roles, true);
    }
}
