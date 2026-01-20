<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120100950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversation_messages (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(32) NOT NULL, content_json LONGTEXT NOT NULL, sequence INT NOT NULL, created_at DATETIME NOT NULL, conversation_id CHAR(36) NOT NULL, INDEX IDX_3B4CA1869AC0396 (conversation_id), INDEX idx_conversation_message_sequence (conversation_id, sequence), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE conversations (id CHAR(36) NOT NULL, workspace_path VARCHAR(4096) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE conversation_messages ADD CONSTRAINT FK_3B4CA1869AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE edit_sessions ADD conversation_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE edit_sessions ADD CONSTRAINT FK_B16E393A9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_B16E393A9AC0396 ON edit_sessions (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_messages DROP FOREIGN KEY FK_3B4CA1869AC0396');
        $this->addSql('DROP TABLE conversation_messages');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('ALTER TABLE edit_sessions DROP FOREIGN KEY FK_B16E393A9AC0396');
        $this->addSql('DROP INDEX IDX_B16E393A9AC0396 ON edit_sessions');
        $this->addSql('ALTER TABLE edit_sessions DROP conversation_id');
    }
}
