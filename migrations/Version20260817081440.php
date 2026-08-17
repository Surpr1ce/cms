<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A version on article and page, so that two people editing the same thing
 * cannot silently overwrite one another.
 *
 * Existing rows default to 1 rather than being rejected, which is what makes
 * this safe to run against a database that already holds content.
 */
final class Version20260817081440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a version to article and page for optimistic locking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE page ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP version');
        $this->addSql('ALTER TABLE page DROP version');
    }
}
