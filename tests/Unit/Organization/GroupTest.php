<?php

declare(strict_types=1);

use App\Organization\Domain\Entity\Group;
use App\Organization\Domain\Entity\Organization;
use App\Organization\Domain\Enum\AccessRight;

describe('Group', function (): void {
    describe('group type detection', function (): void {
        it('isAdministratorsGroup returns true for Administrators group', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Administrators', [AccessRight::FULL_ACCESS], false);
            expect($group->isAdministratorsGroup())->toBeTrue();
        });

        it('isAdministratorsGroup returns false for other groups', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Team Members', [AccessRight::SEE_ORGANIZATION_GROUPS_AND_MEMBERS], true);
            expect($group->isAdministratorsGroup())->toBeFalse();
        });

        it('isTeamMembersGroup returns true for Team Members group', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Team Members', [AccessRight::SEE_ORGANIZATION_GROUPS_AND_MEMBERS], true);
            expect($group->isTeamMembersGroup())->toBeTrue();
        });

        it('isTeamMembersGroup returns false for other groups', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Administrators', [AccessRight::FULL_ACCESS], false);
            expect($group->isTeamMembersGroup())->toBeFalse();
        });
    });

    describe('access rights', function (): void {
        it('stores single access right', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Test Group', [AccessRight::FULL_ACCESS], false);
            expect($group->getAccessRights())->toBe([AccessRight::FULL_ACCESS]);
        });

        it('stores multiple access rights', function (): void {
            $org    = new Organization('user-id-123');
            $rights = [
                AccessRight::EDIT_ORGANIZATION_NAME,
                AccessRight::INVITE_ORGANIZATION_MEMBERS,
            ];
            $group = new Group($org, 'Custom Group', $rights, false);
            expect($group->getAccessRights())->toBe($rights);
        });

        it('can store empty access rights', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'No Access Group', [], false);
            expect($group->getAccessRights())->toBe([]);
        });
    });

    describe('default for new members', function (): void {
        it('isDefaultForNewMembers returns correct value when true', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Default Group', [], true);
            expect($group->isDefaultForNewMembers())->toBeTrue();
        });

        it('isDefaultForNewMembers returns correct value when false', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Non-default Group', [], false);
            expect($group->isDefaultForNewMembers())->toBeFalse();
        });
    });

    describe('organization relationship', function (): void {
        it('returns organization', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Test Group', [], false);
            expect($group->getOrganization())->toBe($org);
        });

        it('returns organization id', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Test Group', [], false);
            expect($group->getOrganizationId())->toBe($org->getId());
        });
    });

    describe('basic properties', function (): void {
        it('returns name', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'My Custom Group', [], false);
            expect($group->getName())->toBe('My Custom Group');
        });

        it('has createdAt timestamp', function (): void {
            $org   = new Organization('user-id-123');
            $group = new Group($org, 'Test Group', [], false);
            expect($group->getCreatedAt())->toBeInstanceOf(DateTimeImmutable::class);
        });
    });
});
