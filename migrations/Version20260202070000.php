<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a "Reviewers" group to all existing organizations.
 *
 * This group grants the REVIEW_WORKSPACES access right, allowing users
 * to access the review functionality (mark workspaces as merged or unlock them).
 */
final class Version20260202070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Reviewers group to existing organizations';
    }

    public function up(Schema $schema): void
    {
        // Insert a Reviewers group for each organization that doesn't have one yet
        // Uses INSERT ... SELECT to create groups for all orgs in one statement
        $this->addSql(<<<'SQL'
            INSERT INTO organization_groups (id, organizations_id, name, access_rights, is_default_for_new_members, created_at)
            SELECT 
                UUID(),
                o.id,
                'Reviewers',
                'review_workspaces',
                0,
                CURDATE()
            FROM organizations o
            WHERE NOT EXISTS (
                SELECT 1 FROM organization_groups g 
                WHERE g.organizations_id = o.id AND g.name = 'Reviewers'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove all Reviewers groups
        $this->addSql("DELETE FROM organization_groups WHERE name = 'Reviewers'");
    }
}
