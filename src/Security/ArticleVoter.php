<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Article;
use App\Entity\User;
use DateTimeImmutable;

use function in_array;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What somebody may do to a particular article.
 *
 * This is the class the whole feature exists for. "May this person edit this
 * article" cannot be answered from a role, because an author may edit their own
 * drafts and nobody else's — the answer depends on the article as much as on the
 * person. A role check has no way to see that, which is why it is not used here.
 *
 * An administrator is granted an editor's permissions by naming both roles
 * explicitly rather than through a hierarchy in configuration. Each grant is
 * then visible where it is made and provable without booting a container. See
 * docs/adr/0009-voters-instead-of-role-hierarchy.md.
 *
 * @extends Voter<string, Article>
 */
final class ArticleVoter extends Voter
{
    public const string VIEW = 'ARTICLE_VIEW';

    public const string EDIT = 'ARTICLE_EDIT';

    public const string DELETE = 'ARTICLE_DELETE';

    /**
     * Every change of publication state — publish, unpublish, archive, restore.
     * One permission rather than four, because the question a person is really
     * asking is "may I decide what the public sees", and the answer does not
     * differ between the four acts.
     */
    public const string PUBLISH = 'ARTICLE_PUBLISH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::PUBLISH], true)
            && $subject instanceof Article;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        // Not signed in, or signed in as something this application does not
        // recognise. Either way there is nothing to grant.
        if (!$user instanceof User) {
            return false;
        }

        if (!$subject instanceof Article) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($subject, $user),
            self::EDIT => $this->canEdit($subject, $user),
            self::DELETE => $this->canDelete($subject, $user),
            self::PUBLISH => $this->isEditorial($user),
            default => false,
        };
    }

    /**
     * Anybody may see published work; that is what published means. Anything
     * else is visible to the editorial roles, and to its own author.
     */
    private function canView(Article $article, User $user): bool
    {
        if ($article->isPublished()) {
            return true;
        }

        return $this->isEditorial($user) || $this->isOwningAuthor($article, $user);
    }

    /**
     * An author may edit their own article while it is a draft.
     *
     * Once it has been published it stops being theirs alone: readers have seen
     * it, it has an address people may have linked to, and changing it is an
     * editorial act. FR-016 states this, and it is the rule most likely to be
     * mistaken for a bug by somebody who has not read the specification.
     */
    private function canEdit(Article $article, User $user): bool
    {
        if ($this->isEditorial($user)) {
            return true;
        }

        return $this->isOwningAuthor($article, $user) && !$this->hasBeenPublished($article);
    }

    private function canDelete(Article $article, User $user): bool
    {
        if ($this->isEditorial($user)) {
            return true;
        }

        return $this->isOwningAuthor($article, $user) && !$this->hasBeenPublished($article);
    }

    /**
     * Published *or ever published*.
     *
     * An article that was published and then unpublished is a draft again by
     * status, but readers have already seen it and its address is frozen. The
     * publication date is the durable record of that, so it is what the rule
     * reads — checking the status alone would hand an unpublished-but-once-public
     * article back to its author.
     */
    private function hasBeenPublished(Article $article): bool
    {
        return $article->getPublishedAt() instanceof DateTimeImmutable;
    }

    /**
     * Ownership *and* the author role, not ownership alone.
     *
     * The distinction is not academic. Roles can be taken away: an account whose
     * author role is revoked still owns everything it wrote, and if ownership
     * alone granted permission it would keep every one of those permissions. The
     * specification says an account holding no roles can sign in and do nothing,
     * and this is the line that makes that true.
     *
     * The first version of this voter checked ownership on its own. The
     * permission matrix caught it — which is the entire reason the matrix
     * enumerates rather than samples.
     */
    private function isOwningAuthor(Article $article, User $user): bool
    {
        return in_array(User::ROLE_AUTHOR, $user->getRoles(), true)
            && $this->owns($article, $user);
    }

    private function owns(Article $article, User $user): bool
    {
        $ownerId = $article->getAuthor()->getId();
        $userId = $user->getId();

        // Unpersisted entities have no identifier, so identity is compared by
        // object where either is new. Comparing two nulls as equal would make
        // every unsaved article belong to everybody.
        if (null === $ownerId || null === $userId) {
            return $article->getAuthor() === $user;
        }

        return $ownerId === $userId;
    }

    private function isEditorial(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(User::ROLE_EDITOR, $roles, true)
            || in_array(User::ROLE_ADMIN, $roles, true);
    }
}
