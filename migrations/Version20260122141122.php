<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Project-Workspace-Conversation workflow.
 * Creates projects and workspaces tables, extends conversations with workspace/user/status.
 */
final class Version20260122141122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create projects and workspaces tables, extend conversations with workspace_id, user_id, status';
    }

    public function up(Schema $schema): void
    {
        // Create projects table
        $this->addSql('CREATE TABLE projects (
            id CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            git_url VARCHAR(2048) NOT NULL,
            github_token VARCHAR(1024) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create workspaces table
        $this->addSql('CREATE TABLE workspaces (
            id CHAR(36) NOT NULL,
            project_id CHAR(36) NOT NULL,
            status INT NOT NULL,
            branch_name VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_workspace_project (project_id),
            INDEX idx_workspace_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Drop existing conversation-related tables and recreate
        $this->addSql('DROP TABLE IF EXISTS edit_session_chunks');
        $this->addSql('DROP TABLE IF EXISTS edit_sessions');
        $this->addSql('DROP TABLE IF EXISTS conversation_messages');
        $this->addSql('DROP TABLE IF EXISTS conversations');

        // Recreate conversations table with new structure
        $this->addSql('CREATE TABLE conversations (
            id CHAR(36) NOT NULL,
            workspace_id CHAR(36) NOT NULL,
            user_id CHAR(36) NOT NULL,
            status VARCHAR(32) NOT NULL,
            workspace_path VARCHAR(4096) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_conversation_workspace (workspace_id),
            INDEX idx_conversation_user (user_id),
            INDEX idx_conversation_workspace_user_status (workspace_id, user_id, status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate edit_sessions table
        $this->addSql('CREATE TABLE edit_sessions (
            id CHAR(36) NOT NULL,
            conversation_id CHAR(36) NOT NULL,
            instruction LONGTEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_EDIT_SESSION_CONVERSATION (conversation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate edit_session_chunks table
        $this->addSql('CREATE TABLE edit_session_chunks (
            id INT AUTO_INCREMENT NOT NULL,
            session_id CHAR(36) NOT NULL,
            chunk_type VARCHAR(32) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            INDEX IDX_CHUNK_SESSION (session_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate conversation_messages table
        $this->addSql('CREATE TABLE conversation_messages (
            id CHAR(36) NOT NULL,
            conversation_id CHAR(36) NOT NULL,
            role VARCHAR(32) NOT NULL,
            content LONGTEXT NOT NULL,
            sequence INT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_MESSAGE_CONVERSATION (conversation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE edit_sessions ADD CONSTRAINT FK_EDIT_SESSION_CONVERSATION FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE edit_session_chunks ADD CONSTRAINT FK_CHUNK_SESSION FOREIGN KEY (session_id) REFERENCES edit_sessions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_messages ADD CONSTRAINT FK_MESSAGE_CONVERSATION FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop constraints first
        $this->addSql('ALTER TABLE conversation_messages DROP FOREIGN KEY FK_MESSAGE_CONVERSATION');
        $this->addSql('ALTER TABLE edit_session_chunks DROP FOREIGN KEY FK_CHUNK_SESSION');
        $this->addSql('ALTER TABLE edit_sessions DROP FOREIGN KEY FK_EDIT_SESSION_CONVERSATION');

        // Drop all new tables
        $this->addSql('DROP TABLE conversation_messages');
        $this->addSql('DROP TABLE edit_session_chunks');
        $this->addSql('DROP TABLE edit_sessions');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE workspaces');
        $this->addSql('DROP TABLE projects');

        // Note: Original conversations/edit_sessions/etc tables would need to be restored
        // This is a destructive migration - cannot fully rollback to original schema
    }
}
