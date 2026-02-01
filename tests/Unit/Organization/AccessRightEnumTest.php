<?php

declare(strict_types=1);

use App\Organization\Domain\Enum\AccessRight;

describe('AccessRight enum', function (): void {
    it('has FULL_ACCESS right', function (): void {
        expect(AccessRight::FULL_ACCESS->value)->toBe('full_access');
    });

    it('has EDIT_ORGANIZATION_NAME right', function (): void {
        expect(AccessRight::EDIT_ORGANIZATION_NAME->value)->toBe('edit_organization_name');
    });

    it('has INVITE_ORGANIZATION_MEMBERS right', function (): void {
        expect(AccessRight::INVITE_ORGANIZATION_MEMBERS->value)->toBe('invite_organization_members');
    });

    it('has SEE_ORGANIZATION_GROUPS_AND_MEMBERS right', function (): void {
        expect(AccessRight::SEE_ORGANIZATION_GROUPS_AND_MEMBERS->value)->toBe('see_organization_groups_and_members');
    });

    it('has MOVE_ORGANIZATION_MEMBERS_INTO_GROUPS right', function (): void {
        expect(AccessRight::MOVE_ORGANIZATION_MEMBERS_INTO_GROUPS->value)->toBe('move_organization_members_into_groups');
    });

    it('has exactly five access rights', function (): void {
        expect(AccessRight::cases())->toHaveCount(5);
    });
});
