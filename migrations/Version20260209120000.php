<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename conversation_messages.role value from 'assistant_note' to 'assistant_note_to_self'
 * for stricter naming (assistant note-to-self).
 */
final class Version20260209120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rename role 'assistant_note' to 'assistant_note_to_self' in conversation_messages";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE conversation_messages SET role = 'assistant_note_to_self' WHERE role = 'assistant_note'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE conversation_messages SET role = 'assistant_note' WHERE role = 'assistant_note_to_self'");
    }
}
