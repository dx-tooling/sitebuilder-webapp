<?php

declare(strict_types=1);

namespace App\Organization\Domain\Service;

use App\Organization\Domain\Entity\Group;
use App\Organization\Domain\Entity\Invitation;
use App\Organization\Domain\Entity\Organization;
use App\Organization\Domain\Enum\AccessRight;

interface OrganizationDomainServiceInterface
{
    /** @return list<Organization> */
    public function getAllOrganizationsForUser(string $userId): array;

    public function userHasJoinedOrganizations(string $userId): bool;

    public function userHasJoinedOrganization(string $userId, string $organizationId): bool;

    public function userCanCreateOrManageOrganization(string $userId): bool;

    public function getOrganizationById(string $organizationId): ?Organization;

    public function createOrganization(string $userId, ?string $name = null): Organization;

    public function renameOrganization(Organization $organization, ?string $name): void;

    public function emailCanBeInvitedToOrganization(string $email, Organization $organization): bool;

    public function inviteEmailToOrganization(string $email, Organization $organization): ?Invitation;

    public function acceptInvitation(Invitation $invitation, ?string $userId): ?string;

    public function getOrganizationName(Organization $organization): string;

    public function hasPendingInvitations(Organization $organization): bool;

    /** @return list<Invitation> */
    public function getPendingInvitations(Organization $organization): array;

    public function resendInvitation(Invitation $invitation): void;

    /** @return list<string> */
    public function getAllUserIdsForOrganization(Organization $organization): array;

    /** @return list<Group> */
    public function getGroups(Organization $organization): array;

    /** @return list<Group> */
    public function getGroupsOfUserForCurrentlyActiveOrganization(string $userId): array;

    public function getDefaultGroupForNewMembers(Organization $organization): Group;

    /** @return list<string> */
    public function getGroupMemberIds(Group $group): array;

    public function addUserToGroup(string $userId, Group $group): void;

    public function removeUserFromGroup(string $userId, Group $group): void;

    public function getGroupById(string $groupId): ?Group;

    public function moveUserToAdministratorsGroup(string $userId, Organization $organization): void;

    public function moveUserToTeamMembersGroup(string $userId, Organization $organization): void;

    public function userHasAccessRight(string $userId, AccessRight $accessRight): bool;

    public function currentlyActiveOrganizationIsOwnOrganization(string $userId): bool;

    public function userCanSwitchOrganizations(string $userId): bool;

    /** @return list<Organization> */
    public function organizationsUserCanSwitchTo(string $userId): array;

    public function switchOrganization(string $userId, Organization $organization): void;
}
