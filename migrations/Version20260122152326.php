<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260122152326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project_type column to projects table';
    }

    public function up(Schema $schema): void
    {
        // Add column with default value for existing rows
        $this->addSql("ALTER TABLE projects ADD project_type VARCHAR(32) NOT NULL DEFAULT 'default'");
        // Remove the default after migration (column should require explicit value)
        $this->addSql('ALTER TABLE projects ALTER COLUMN project_type DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects DROP project_type');
    }
}
