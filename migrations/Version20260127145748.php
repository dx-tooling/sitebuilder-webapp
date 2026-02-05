<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260127145748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects ADD s3_bucket_name VARCHAR(255) DEFAULT NULL, ADD s3_region VARCHAR(64) DEFAULT NULL, ADD s3_access_key_id VARCHAR(256) DEFAULT NULL, ADD s3_secret_access_key VARCHAR(256) DEFAULT NULL, ADD s3_iam_role_arn VARCHAR(2048) DEFAULT NULL, ADD s3_key_prefix VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects DROP s3_bucket_name, DROP s3_region, DROP s3_access_key_id, DROP s3_secret_access_key, DROP s3_iam_role_arn, DROP s3_key_prefix');
    }
}
