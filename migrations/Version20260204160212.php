<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add keys_visible to projects (prefab feature).
 * NULL = legacy/visible (true); false = keys hidden from org users.
 */
final class Version20260204160212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add keys_visible column to projects for prefab key visibility';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects ADD keys_visible TINYINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects DROP keys_visible');
    }
}
