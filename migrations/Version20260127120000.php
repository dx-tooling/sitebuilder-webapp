<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content_assets_manifest_urls column to projects table (JSON array of manifest URLs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD content_assets_manifest_urls JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP content_assets_manifest_urls');
    }
}
