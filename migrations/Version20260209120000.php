<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename conversation_messages.role value from 'assistant_note' to 'turn_activity_summary'.
 * Originally this role was called 'assistant_note' (note-to-self concept), then briefly
 * 'assistant_note_to_self', now 'turn_activity_summary' (automatic turn activity journal).
 */
final class Version20260209120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rename role 'assistant_note' to 'turn_activity_summary' in conversation_messages";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE conversation_messages SET role = 'turn_activity_summary' WHERE role = 'assistant_note'");
        $this->addSql("UPDATE conversation_messages SET role = 'turn_activity_summary' WHERE role = 'assistant_note_to_self'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE conversation_messages SET role = 'assistant_note' WHERE role = 'turn_activity_summary'");
    }
}
