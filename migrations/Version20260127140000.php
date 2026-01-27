<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260127140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename content_assets_manifest_urls to remote_content_assets_manifest_urls on projects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects RENAME COLUMN content_assets_manifest_urls TO remote_content_assets_manifest_urls');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects RENAME COLUMN remote_content_assets_manifest_urls TO content_assets_manifest_urls');
    }
}
