<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page nesting and menu position.
 *
 * ON DELETE RESTRICT rather than SET NULL, because page nesting is also the
 * site's menu: a page with children below it is refused rather than having its
 * navigation silently rearranged.
 */
final class Version20260816214219 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add page nesting and menu order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page ADD menu_order INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE page ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE page ADD CONSTRAINT FK_140AB620727ACA70 FOREIGN KEY (parent_id) REFERENCES page (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_140AB620727ACA70 ON page (parent_id)');
        $this->addSql('CREATE INDEX idx_page_menu ON page (parent_id, menu_order)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page DROP CONSTRAINT FK_140AB620727ACA70');
        $this->addSql('DROP INDEX IDX_140AB620727ACA70');
        $this->addSql('DROP INDEX idx_page_menu');
        $this->addSql('ALTER TABLE page DROP menu_order');
        $this->addSql('ALTER TABLE page DROP parent_id');
    }
}
