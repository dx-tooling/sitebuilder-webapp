<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120120440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Conversation entity and link EditSession to it; drop workspace_path from edit_sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('CREATE TABLE conversations (id CHAR(36) NOT NULL, workspace_path VARCHAR(4096) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->connection->executeStatement('ALTER TABLE edit_sessions ADD conversation_id CHAR(36) DEFAULT NULL');

        $rows = $this->connection->fetchAllAssociative('SELECT id, workspace_path, created_at FROM edit_sessions');
        foreach ($rows as $row) {
            $uuid = $this->connection->fetchOne('SELECT UUID()');
            $this->connection->executeStatement(
                'INSERT INTO conversations (id, workspace_path, created_at) VALUES (?, ?, ?)',
                [$uuid, $row['workspace_path'], $row['created_at']]
            );
            $this->connection->executeStatement(
                'UPDATE edit_sessions SET conversation_id = ? WHERE id = ?',
                [$uuid, $row['id']]
            );
        }

        $this->addSql('ALTER TABLE edit_sessions MODIFY conversation_id CHAR(36) NOT NULL');
        $this->addSql('ALTER TABLE edit_sessions DROP workspace_path');
        $this->addSql('ALTER TABLE edit_sessions ADD CONSTRAINT FK_B16E393A9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_B16E393A9AC0396 ON edit_sessions (conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE conversations');
        $this->addSql('ALTER TABLE edit_sessions DROP FOREIGN KEY FK_B16E393A9AC0396');
        $this->addSql('DROP INDEX IDX_B16E393A9AC0396 ON edit_sessions');
        $this->addSql('ALTER TABLE edit_sessions ADD workspace_path VARCHAR(4096) NOT NULL, DROP conversation_id');
    }
}
