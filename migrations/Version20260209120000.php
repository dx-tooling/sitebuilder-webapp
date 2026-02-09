<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the 'turn_activity_summary' role to conversation_messages.
 *
 * Renames any legacy role values ('assistant_note', 'assistant_note_to_self')
 * that may exist from earlier development iterations to the canonical name.
 */
final class Version20260209120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ensure conversation_messages use 'turn_activity_summary' role for turn activity summaries";
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
