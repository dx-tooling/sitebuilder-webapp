<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename LLM fields to content-editing scope and add PhotoBuilder LLM fields.
 */
final class Version20260211110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename llm_model_provider/llm_api_key to content_editing_* scope and add photo_builder_* LLM fields';
    }

    public function up(Schema $schema): void
    {
        // Rename existing columns to content-editing scope
        $this->addSql('ALTER TABLE projects CHANGE llm_model_provider content_editing_llm_model_provider VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE projects CHANGE llm_api_key content_editing_llm_model_provider_api_key VARCHAR(1024) NOT NULL');

        // Add PhotoBuilder-specific LLM columns (nullable = uses content editing settings)
        $this->addSql('ALTER TABLE projects ADD photo_builder_llm_model_provider VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD photo_builder_llm_model_provider_api_key VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP photo_builder_llm_model_provider_api_key');
        $this->addSql('ALTER TABLE projects DROP photo_builder_llm_model_provider');
        $this->addSql('ALTER TABLE projects CHANGE content_editing_llm_model_provider_api_key llm_api_key VARCHAR(1024) NOT NULL');
        $this->addSql('ALTER TABLE projects CHANGE content_editing_llm_model_provider llm_model_provider VARCHAR(32) NOT NULL');
    }
}
