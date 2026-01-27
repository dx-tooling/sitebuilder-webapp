<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260127145023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversations ADD content_editor_backend VARCHAR(32) DEFAULT \'llm\' NOT NULL, ADD cursor_agent_session_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD content_editor_backend VARCHAR(32) DEFAULT \'llm\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversations DROP content_editor_backend, DROP cursor_agent_session_id');
        $this->addSql('ALTER TABLE projects DROP content_editor_backend');
    }
}
