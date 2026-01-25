<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add per-project agent configuration fields to projects table.
 */
final class Version20260125100259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agent configuration fields (background, step, output instructions) to projects table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD agent_background_instructions LONGTEXT NOT NULL, ADD agent_step_instructions LONGTEXT NOT NULL, ADD agent_output_instructions LONGTEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP agent_background_instructions, DROP agent_step_instructions, DROP agent_output_instructions');
    }
}
