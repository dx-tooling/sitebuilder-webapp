<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120085846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE edit_session_chunks (id INT AUTO_INCREMENT NOT NULL, chunk_type VARCHAR(32) NOT NULL, payload_json LONGTEXT NOT NULL, created_at DATETIME NOT NULL, session_id CHAR(36) NOT NULL, INDEX IDX_D9E57D76613FECDF (session_id), INDEX idx_session_chunk_polling (session_id, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE edit_sessions (id CHAR(36) NOT NULL, workspace_path VARCHAR(4096) NOT NULL, instruction LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE edit_session_chunks ADD CONSTRAINT FK_D9E57D76613FECDF FOREIGN KEY (session_id) REFERENCES edit_sessions (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE edit_session_chunks DROP FOREIGN KEY FK_D9E57D76613FECDF');
        $this->addSql('DROP TABLE edit_session_chunks');
        $this->addSql('DROP TABLE edit_sessions');
    }
}
