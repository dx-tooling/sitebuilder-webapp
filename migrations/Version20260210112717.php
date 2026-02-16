<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260210112717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create photo_sessions and photo_images tables for the PhotoBuilder feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE photo_images (id CHAR(36) NOT NULL, position INT NOT NULL, prompt LONGTEXT DEFAULT NULL, suggested_file_name VARCHAR(512) DEFAULT NULL, status VARCHAR(32) NOT NULL, storage_path VARCHAR(1024) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, session_id CHAR(36) NOT NULL, INDEX IDX_B5A0C942613FECDF (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE photo_sessions (id CHAR(36) NOT NULL, workspace_id CHAR(36) NOT NULL, conversation_id CHAR(36) NOT NULL, page_path VARCHAR(512) NOT NULL, user_prompt LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE photo_images ADD CONSTRAINT FK_B5A0C942613FECDF FOREIGN KEY (session_id) REFERENCES photo_sessions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE photo_images DROP FOREIGN KEY FK_B5A0C942613FECDF');
        $this->addSql('DROP TABLE photo_images');
        $this->addSql('DROP TABLE photo_sessions');
    }
}
