<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260125082332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BYOK LLM fields (llm_model_provider, llm_api_key) to projects table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects ADD llm_model_provider VARCHAR(32) NOT NULL, ADD llm_api_key VARCHAR(1024) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects DROP llm_model_provider, DROP llm_api_key');
    }
}
