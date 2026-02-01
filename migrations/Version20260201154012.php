<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201154012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE organization_group_members (
              account_cores_id CHAR(36) NOT NULL,
              organization_groups_id CHAR(36) NOT NULL,
              INDEX IDX_DED071633700E7C9 (organization_groups_id),
              PRIMARY KEY (
                account_cores_id, organization_groups_id
              )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organization_groups (
              id CHAR(36) NOT NULL,
              name VARCHAR(256) NOT NULL,
              created_at DATE NOT NULL,
              access_rights TEXT NOT NULL,
              is_default_for_new_members TINYINT NOT NULL,
              organizations_id CHAR(36) NOT NULL,
              INDEX IDX_F5E3E98586288A55 (organizations_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organization_invitations (
              id CHAR(36) NOT NULL,
              email VARCHAR(256) NOT NULL,
              created_at DATE NOT NULL,
              organizations_id CHAR(36) NOT NULL,
              INDEX IDX_137BB4D586288A55 (organizations_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organization_members (
              account_cores_id CHAR(36) NOT NULL,
              organizations_id CHAR(36) NOT NULL,
              INDEX IDX_88725ABC86288A55 (organizations_id),
              PRIMARY KEY (
                account_cores_id, organizations_id
              )
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organizations (
              id CHAR(36) NOT NULL,
              owning_users_id CHAR(36) NOT NULL,
              name VARCHAR(256) DEFAULT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_group_members
            ADD
              CONSTRAINT FK_DED071633700E7C9 FOREIGN KEY (organization_groups_id) REFERENCES organization_groups (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_groups
            ADD
              CONSTRAINT FK_F5E3E98586288A55 FOREIGN KEY (organizations_id) REFERENCES organizations (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_invitations
            ADD
              CONSTRAINT FK_137BB4D586288A55 FOREIGN KEY (organizations_id) REFERENCES organizations (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              organization_members
            ADD
              CONSTRAINT FK_88725ABC86288A55 FOREIGN KEY (organizations_id) REFERENCES organizations (id) ON DELETE CASCADE
        SQL);
        $this->addSql('ALTER TABLE account_cores ADD currently_active_organization_id CHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD organization_id CHAR(36) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_group_members DROP FOREIGN KEY FK_DED071633700E7C9');
        $this->addSql('ALTER TABLE organization_groups DROP FOREIGN KEY FK_F5E3E98586288A55');
        $this->addSql('ALTER TABLE organization_invitations DROP FOREIGN KEY FK_137BB4D586288A55');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABC86288A55');
        $this->addSql('DROP TABLE organization_group_members');
        $this->addSql('DROP TABLE organization_groups');
        $this->addSql('DROP TABLE organization_invitations');
        $this->addSql('DROP TABLE organization_members');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('ALTER TABLE account_cores DROP currently_active_organization_id');
        $this->addSql('ALTER TABLE projects DROP organization_id');
    }
}
