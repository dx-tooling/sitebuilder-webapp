<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122142201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_messages CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE content content_json LONGTEXT NOT NULL');
        $this->addSql('CREATE INDEX idx_conversation_message_sequence ON conversation_messages (conversation_id, sequence)');
        $this->addSql('ALTER TABLE conversation_messages RENAME INDEX idx_message_conversation TO IDX_3B4CA1869AC0396');
        $this->addSql('ALTER TABLE conversations CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE edit_session_chunks ADD created_at DATETIME NOT NULL');
        $this->addSql('CREATE INDEX idx_session_chunk_polling ON edit_session_chunks (session_id, id)');
        $this->addSql('ALTER TABLE edit_session_chunks RENAME INDEX idx_chunk_session TO IDX_D9E57D76613FECDF');
        $this->addSql('ALTER TABLE edit_sessions CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE edit_sessions RENAME INDEX idx_edit_session_conversation TO IDX_B16E393A9AC0396');
        $this->addSql('ALTER TABLE projects CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE workspaces CHANGE created_at created_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversations CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP INDEX idx_conversation_message_sequence ON conversation_messages');
        $this->addSql('ALTER TABLE conversation_messages CHANGE id id CHAR(36) NOT NULL, CHANGE content_json content LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE conversation_messages RENAME INDEX idx_3b4ca1869ac0396 TO IDX_MESSAGE_CONVERSATION');
        $this->addSql('ALTER TABLE edit_sessions CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE edit_sessions RENAME INDEX idx_b16e393a9ac0396 TO IDX_EDIT_SESSION_CONVERSATION');
        $this->addSql('DROP INDEX idx_session_chunk_polling ON edit_session_chunks');
        $this->addSql('ALTER TABLE edit_session_chunks DROP created_at');
        $this->addSql('ALTER TABLE edit_session_chunks RENAME INDEX idx_d9e57d76613fecdf TO IDX_CHUNK_SESSION');
        $this->addSql('ALTER TABLE projects CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE workspaces CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
