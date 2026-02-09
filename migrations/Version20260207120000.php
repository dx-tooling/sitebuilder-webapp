<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add context_bytes to edit_session_chunks for accurate AI budget tracking.
 * Stores actual byte length of tool inputs+results per event chunk; NULL for legacy rows.
 */
final class Version20260207120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add context_bytes to edit_session_chunks for budget tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE edit_session_chunks ADD context_bytes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE edit_session_chunks DROP context_bytes');
    }
}
