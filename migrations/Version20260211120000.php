<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename cursor_agent_session_id to backend_session_state on conversations.
 * Opaque session state is backend-agnostic (e.g. Cursor session ID for Cursor backend).
 */
final class Version20260211120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename conversations.cursor_agent_session_id to backend_session_state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversations RENAME COLUMN cursor_agent_session_id TO backend_session_state');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversations RENAME COLUMN backend_session_state TO cursor_agent_session_id');
    }
}
