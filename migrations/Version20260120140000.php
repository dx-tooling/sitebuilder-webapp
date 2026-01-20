<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add conversation_messages table for multi-turn chat support.
 */
final class Version20260120140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversation_messages table for storing conversation history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conversation_messages (id INT AUTO_INCREMENT NOT NULL, conversation_id CHAR(36) NOT NULL, role VARCHAR(32) NOT NULL, content_json LONGTEXT NOT NULL, sequence INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3B4CA1869AC0396 (conversation_id), INDEX idx_conversation_message_sequence (conversation_id, sequence), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE conversation_messages ADD CONSTRAINT FK_3B4CA1869AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation_messages DROP FOREIGN KEY FK_3B4CA1869AC0396');
        $this->addSql('DROP TABLE conversation_messages');
    }
}
