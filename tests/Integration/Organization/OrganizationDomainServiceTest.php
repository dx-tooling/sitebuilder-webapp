<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Account\Domain\Service\AccountDomainService;
use App\Organization\Domain\Entity\Group;
use App\Organization\Domain\Enum\AccessRight;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationDomainServiceTest extends KernelTestCase
{
    private OrganizationDomainServiceInterface $service;
    private AccountDomainService $accountDomainService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var OrganizationDomainServiceInterface $service */
        $service       = $container->get(OrganizationDomainServiceInterface::class);
        $this->service = $service;

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;
    }

    private function createTestUser(): string
    {
        $email   = 'orgtest-' . uniqid() . '@example.com';
        $account = $this->accountDomainService->register($email, 'password123');

        $accountId = $account->getId();
        self::assertNotNull($accountId, 'User registration should return a user ID');

        return $accountId;
    }

    /**
     * @param list<Group> $groups
     */
    private function findAdminGroup(array $groups): Group
    {
        foreach ($groups as $group) {
            if ($group->isAdministratorsGroup()) {
                return $group;
            }
        }
        self::fail('Admin group not found');
    }

    /**
     * @param list<Group> $groups
     */
    private function findReviewersGroup(array $groups): Group
    {
        foreach ($groups as $group) {
            if ($group->isReviewersGroup()) {
                return $group;
            }
        }
        self::fail('Reviewers group not found');
    }

    public function testCreateOrganizationCreatesWithOwningUser(): void
    {
        $userId = $this->createTestUser();

        // The user should already have an organization created via event
        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);
        $this->assertSame($userId, $organizations[0]->getOwningUsersId());
    }

    public function testCreateOrganizationCreatesDefaultGroups(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);

        $groups = $this->service->getGroups($organizations[0]);

        $this->assertCount(3, $groups);

        $groupNames = array_map(fn (Group $g): string => $g->getName(), $groups);
        $this->assertContains('Administrators', $groupNames);
        $this->assertContains('Team Members', $groupNames);
        $this->assertContains('Reviewers', $groupNames);
    }

    public function testCreateOrganizationAdministratorsGroupHasFullAccess(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);

        $groups     = $this->service->getGroups($organizations[0]);
        $adminGroup = $this->findAdminGroup($groups);

        $this->assertContains(AccessRight::FULL_ACCESS, $adminGroup->getAccessRights());
    }

    public function testCreateOrganizationTeamMembersIsDefaultForNewMembers(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);

        $defaultGroup = $this->service->getDefaultGroupForNewMembers($organizations[0]);

        $this->assertTrue($defaultGroup->isTeamMembersGroup());
    }

    public function testRenameOrganization(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);
        $organization = $organizations[0];

        $this->service->renameOrganization($organization, 'New Name');

        $this->assertSame('New Name', $organization->getName());
    }

    public function testRenameOrganizationToNull(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);
        $organization = $organizations[0];

        $organization->setName('Has Name');
        $this->service->renameOrganization($organization, null);

        $this->assertNull($organization->getName());
    }

    public function testGetOrganizationById(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);
        $created = $organizations[0];

        $found = $this->service->getOrganizationById($created->getId());

        $this->assertNotNull($found);
        $this->assertSame($created->getId(), $found->getId());
    }

    public function testGetOrganizationByIdReturnsNullForUnknown(): void
    {
        $found = $this->service->getOrganizationById('nonexistent-org-id');

        $this->assertNull($found);
    }

    public function testGetAllOrganizationsForUserIncludesOwned(): void
    {
        $userId = $this->createTestUser();
        // Note: Account creation automatically creates an organization

        $organizations = $this->service->getAllOrganizationsForUser($userId);

        $this->assertNotEmpty($organizations);
        $ownerIds = array_map(fn (\App\Organization\Domain\Entity\Organization $o): string => $o->getOwningUsersId(), $organizations);
        $this->assertContains($userId, $ownerIds);
    }

    public function testCurrentlyActiveOrganizationIsOwnOrganization(): void
    {
        $userId = $this->createTestUser();

        $isOwn = $this->service->currentlyActiveOrganizationIsOwnOrganization($userId);

        $this->assertTrue($isOwn);
    }

    public function testUserHasAccessRightReturnsTrueForOwner(): void
    {
        $userId = $this->createTestUser();

        // Owner has all access rights
        $hasRight = $this->service->userHasAccessRight($userId, AccessRight::FULL_ACCESS);

        $this->assertTrue($hasRight);
    }

    public function testUserCanSwitchOrganizationsReturnsFalseWithSingleOrg(): void
    {
        $userId = $this->createTestUser();

        $canSwitch = $this->service->userCanSwitchOrganizations($userId);

        $this->assertFalse($canSwitch);
    }

    public function testGetAllUserIdsForOrganizationIncludesOwner(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);
        $organization = $organizations[0];

        $userIds = $this->service->getAllUserIdsForOrganization($organization);

        $this->assertContains($userId, $userIds);
    }

    public function testCreateOrganizationReviewersGroupHasReviewWorkspacesAccess(): void
    {
        $userId = $this->createTestUser();

        $organizations = $this->service->getAllOrganizationsForUser($userId);
        $this->assertNotEmpty($organizations);

        $groups         = $this->service->getGroups($organizations[0]);
        $reviewersGroup = $this->findReviewersGroup($groups);

        $this->assertContains(AccessRight::REVIEW_WORKSPACES, $reviewersGroup->getAccessRights());
    }

    public function testUserHasAccessRightReviewWorkspacesReturnsTrueForOwner(): void
    {
        $userId = $this->createTestUser();

        // Owner has all access rights including review workspaces
        $hasRight = $this->service->userHasAccessRight($userId, AccessRight::REVIEW_WORKSPACES);

        $this->assertTrue($hasRight);
    }
}
