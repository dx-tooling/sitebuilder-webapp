<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\Organization\Domain\Entity\Group;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Infrastructure\Repository\OrganizationRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationRepositoryTest extends KernelTestCase
{
    private OrganizationRepositoryInterface $repository;
    private OrganizationDomainServiceInterface $orgService;
    private AccountDomainService $accountDomainService;
    private AccountFacadeInterface $accountFacade;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var OrganizationRepositoryInterface $repository */
        $repository       = $container->get(OrganizationRepositoryInterface::class);
        $this->repository = $repository;

        /** @var OrganizationDomainServiceInterface $orgService */
        $orgService       = $container->get(OrganizationDomainServiceInterface::class);
        $this->orgService = $orgService;

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade       = $container->get(AccountFacadeInterface::class);
        $this->accountFacade = $accountFacade;
    }

    private function createTestUser(): string
    {
        $email   = 'repotest-' . uniqid() . '@example.com';
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

    public function testFindByIdReturnsOrganization(): void
    {
        $userId = $this->createTestUser();
        $orgId  = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($orgId, 'User should have an active organization');

        $found = $this->repository->findById($orgId);

        $this->assertNotNull($found);
        $this->assertSame($orgId, $found->getId());
    }

    public function testFindByIdReturnsNullForUnknown(): void
    {
        $found = $this->repository->findById('nonexistent-id-12345');

        $this->assertNull($found);
    }

    public function testGetAllOrganizationsForUserReturnsOwnedOrg(): void
    {
        $userId = $this->createTestUser();

        $orgs = $this->repository->getAllOrganizationsForUser($userId);

        $this->assertNotEmpty($orgs);
        $ownerIds = array_map(fn (\App\Organization\Domain\Entity\Organization $o): string => $o->getOwningUsersId(), $orgs);
        $this->assertContains($userId, $ownerIds);
    }

    public function testUserHasJoinedOrganizationsReturnsTrueWhenMember(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $this->repository->addUserToOrganization($memberUserId, $ownerOrgId);

        $this->assertTrue($this->repository->userHasJoinedOrganizations($memberUserId));
    }

    public function testUserHasJoinedOrganizationReturnsTrueForSpecificOrg(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $this->repository->addUserToOrganization($memberUserId, $ownerOrgId);

        $this->assertTrue($this->repository->userHasJoinedOrganization($memberUserId, $ownerOrgId));
    }

    public function testUserHasJoinedOrganizationReturnsFalseForNonMember(): void
    {
        $ownerUserId     = $this->createTestUser();
        $nonMemberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $this->assertFalse($this->repository->userHasJoinedOrganization($nonMemberUserId, $ownerOrgId));
    }

    public function testAddUserToOrganizationCreatesMembership(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $this->repository->addUserToOrganization($memberUserId, $ownerOrgId);

        $orgs   = $this->repository->getAllOrganizationsForUser($memberUserId);
        $orgIds = array_map(fn (\App\Organization\Domain\Entity\Organization $o): string => $o->getId(), $orgs);
        $this->assertContains($ownerOrgId, $orgIds);
    }

    public function testAddMemberToGroupAndGetMemberIds(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $organization = $this->repository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization should exist');

        $groups     = $this->orgService->getGroups($organization);
        $adminGroup = $this->findAdminGroup($groups);

        $this->repository->addMemberToGroup($memberUserId, $adminGroup->getId());

        $memberIds = $this->repository->getMemberIdsOfGroup($adminGroup->getId());
        $this->assertContains($memberUserId, $memberIds);
    }

    public function testRemoveMemberFromGroup(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $organization = $this->repository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization should exist');

        $groups     = $this->orgService->getGroups($organization);
        $adminGroup = $this->findAdminGroup($groups);

        $this->repository->addMemberToGroup($memberUserId, $adminGroup->getId());
        $this->repository->removeMemberFromGroup($memberUserId, $adminGroup->getId());

        $memberIds = $this->repository->getMemberIdsOfGroup($adminGroup->getId());
        $this->assertNotContains($memberUserId, $memberIds);
    }

    public function testUserIsMemberOfGroupReturnsTrue(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $organization = $this->repository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization should exist');

        $groups     = $this->orgService->getGroups($organization);
        $adminGroup = $this->findAdminGroup($groups);

        $this->repository->addMemberToGroup($memberUserId, $adminGroup->getId());

        $this->assertTrue($this->repository->userIsMemberOfGroup($memberUserId, $adminGroup->getId()));
    }

    public function testUserIsMemberOfGroupReturnsFalse(): void
    {
        $ownerUserId     = $this->createTestUser();
        $nonMemberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $organization = $this->repository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization should exist');

        $groups     = $this->orgService->getGroups($organization);
        $adminGroup = $this->findAdminGroup($groups);

        $this->assertFalse($this->repository->userIsMemberOfGroup($nonMemberUserId, $adminGroup->getId()));
    }

    public function testGetJoinedUserIdsForOrganization(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $this->repository->addUserToOrganization($memberUserId, $ownerOrgId);

        $joinedUserIds = $this->repository->getJoinedUserIdsForOrganization($ownerOrgId);

        $this->assertContains($memberUserId, $joinedUserIds);
        // Owner is not in "joined" list, they own it
        $this->assertNotContains($ownerUserId, $joinedUserIds);
    }

    public function testGetGroupIdsOfUser(): void
    {
        $ownerUserId  = $this->createTestUser();
        $memberUserId = $this->createTestUser();

        $ownerOrgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner should have an active organization');

        $organization = $this->repository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization should exist');

        $groups     = $this->orgService->getGroups($organization);
        $adminGroup = $this->findAdminGroup($groups);

        $this->repository->addMemberToGroup($memberUserId, $adminGroup->getId());

        $groupIds = $this->repository->getGroupIdsOfUser($memberUserId);
        $this->assertContains($adminGroup->getId(), $groupIds);
    }
}
