<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds organization management system.
 *
 * Creates:
 * - organizations table
 * - organization_members table (join table for user membership)
 * - organization_groups table (permission groups within orgs)
 * - organization_group_members table (join table for group membership)
 * - organization_invitations table
 *
 * Modifies:
 * - account_cores: adds currently_active_organization_id
 * - projects: adds organization_id
 *
 * Data migration (in postUp):
 * - Creates a single organization owned by the oldest user
 * - Adds all other users as members in the Team Members group
 * - Associates all projects with the organization
 */
final class Version20260201154012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organization management system with data migration';
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
        // Add organization_id as nullable first, postUp will populate and make it NOT NULL
        $this->addSql('ALTER TABLE projects ADD organization_id CHAR(36) DEFAULT NULL');
    }

    public function postUp(Schema $schema): void
    {
        // Data migration: Create organization and set up membership
        $connection = $this->connection;

        // Check if there are any users
        $userCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM account_cores');
        if ($userCount === 0) {
            // No users yet, make organization_id NOT NULL and return
            $connection->executeStatement('ALTER TABLE projects MODIFY organization_id CHAR(36) NOT NULL');

            return;
        }

        // Get the oldest user
        $oldestUser = $connection->fetchAssociative(
            'SELECT id, email FROM account_cores ORDER BY created_at ASC LIMIT 1'
        );

        if ($oldestUser === false) {
            return;
        }

        $oldestUserId    = $oldestUser['id'];
        $oldestUserEmail = $oldestUser['email'];

        // Generate UUIDs for organization and groups
        $orgId        = $this->generateUuid();
        $adminGroupId = $this->generateUuid();
        $teamGroupId  = $this->generateUuid();

        // Create organization name from email (use part before @)
        $emailParts = explode('@', $oldestUserEmail);
        $orgName    = $emailParts[0] . "'s Organization";

        // Create the organization
        $connection->executeStatement(
            'INSERT INTO organizations (id, owning_users_id, name) VALUES (?, ?, ?)',
            [$orgId, $oldestUserId, $orgName]
        );

        // Create default groups
        $today = date('Y-m-d');

        // Administrators group (FULL_ACCESS, not default)
        $connection->executeStatement(
            'INSERT INTO organization_groups (id, organizations_id, name, access_rights, is_default_for_new_members, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$adminGroupId, $orgId, 'Administrators', 'full_access', 0, $today]
        );

        // Team Members group (SEE_ORGANIZATION_GROUPS_AND_MEMBERS, default for new members)
        $connection->executeStatement(
            'INSERT INTO organization_groups (id, organizations_id, name, access_rights, is_default_for_new_members, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$teamGroupId, $orgId, 'Team Members', 'see_organization_groups_and_members', 1, $today]
        );

        // Add all other users as members and to Team Members group
        $otherUsers = $connection->fetchAllAssociative(
            'SELECT id FROM account_cores WHERE id != ?',
            [$oldestUserId]
        );

        foreach ($otherUsers as $user) {
            $userId = $user['id'];

            // Add to organization_members
            $connection->executeStatement(
                'INSERT INTO organization_members (account_cores_id, organizations_id) VALUES (?, ?)',
                [$userId, $orgId]
            );

            // Add to Team Members group
            $connection->executeStatement(
                'INSERT INTO organization_group_members (account_cores_id, organization_groups_id) VALUES (?, ?)',
                [$userId, $teamGroupId]
            );
        }

        // Update all projects with the organization_id
        $connection->executeStatement(
            'UPDATE projects SET organization_id = ?',
            [$orgId]
        );

        // Set currently_active_organization_id for all users
        $connection->executeStatement(
            'UPDATE account_cores SET currently_active_organization_id = ?',
            [$orgId]
        );

        // Make organization_id NOT NULL now that all projects have it
        $connection->executeStatement('ALTER TABLE projects MODIFY organization_id CHAR(36) NOT NULL');
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

    private function generateUuid(): string
    {
        // Generate a UUID v4
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
