<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260118085244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_cores (id CHAR(36) NOT NULL, created_at DATETIME NOT NULL, email VARCHAR(1024) NOT NULL, password_hash VARCHAR(1024) NOT NULL, roles JSON NOT NULL, UNIQUE INDEX UNIQ_CB89C7FEE7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_cores');
    }
}
