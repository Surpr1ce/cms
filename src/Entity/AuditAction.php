<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The decisions this CMS records.
 *
 * A closed list, and short on purpose. Editing an article's body is not here:
 * a log of every field of every save is a log nobody reads and a database twice
 * the size to store the fact that somebody fixed a typo.
 *
 * What is here is the set of things that either change what the public can see,
 * remove something, or change what somebody is allowed to do — the three
 * questions a site with more than one editor actually asks.
 */
enum AuditAction: string
{
    case ContentPublished = 'content.published';

    case ContentUnpublished = 'content.unpublished';

    case ContentArchived = 'content.archived';

    case ContentRestored = 'content.restored';

    case ContentDeleted = 'content.deleted';

    case FileDeleted = 'file.deleted';

    /**
     * Deleting a section uncategorises every article in it and moves its
     * subsections up a level — a change to a great deal of content, made by one
     * click, and invisible afterwards from anywhere except here. It is exactly
     * the sort of thing somebody reads a log to explain.
     */
    case SectionDeleted = 'section.deleted';

    case LabelDeleted = 'label.deleted';

    case AccountCreated = 'account.created';

    case AccountDeleted = 'account.deleted';

    /**
     * What somebody is allowed to do, changed. Recorded separately from any
     * other account edit because it is the only one that grants authority.
     */
    case AccountPermissionsChanged = 'account.permissions_changed';

    /**
     * That a password changed — never what it changed to, and never by which
     * route it was proved. Both a reset and a deliberate change land here,
     * because what a reader needs to know is that the credential moved.
     */
    case PasswordChanged = 'account.password_changed';

    /**
     * A sentence, in the past tense, for somebody reading a list of them.
     */
    public function label(): string
    {
        return match ($this) {
            self::ContentPublished => 'published',
            self::ContentUnpublished => 'unpublished',
            self::ContentArchived => 'archived',
            self::ContentRestored => 'restored',
            self::ContentDeleted => 'deleted',
            self::FileDeleted => 'deleted the file',
            self::SectionDeleted => 'deleted the section',
            self::LabelDeleted => 'deleted the label',
            self::AccountCreated => 'created the account',
            self::AccountDeleted => 'deleted the account',
            self::AccountPermissionsChanged => 'changed the permissions of',
            self::PasswordChanged => 'changed the password of',
        };
    }
}
