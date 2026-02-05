<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\Organization\Domain\Entity\Invitation;
use App\Organization\Domain\Entity\Organization;
use App\Organization\Domain\Service\OrganizationDomainServiceInterface;
use App\Organization\Infrastructure\Repository\OrganizationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvitationWorkflowTest extends KernelTestCase
{
    private OrganizationDomainServiceInterface $orgService;
    private AccountFacadeInterface $accountFacade;
    private AccountDomainService $accountDomainService;
    private OrganizationRepositoryInterface $orgRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var OrganizationDomainServiceInterface $orgService */
        $orgService       = $container->get(OrganizationDomainServiceInterface::class);
        $this->orgService = $orgService;

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade       = $container->get(AccountFacadeInterface::class);
        $this->accountFacade = $accountFacade;

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;

        /** @var OrganizationRepositoryInterface $orgRepository */
        $orgRepository       = $container->get(OrganizationRepositoryInterface::class);
        $this->orgRepository = $orgRepository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    private function createTestUser(string $emailPrefix = 'invtest'): string
    {
        $email   = $emailPrefix . '-' . uniqid() . '@example.com';
        $account = $this->accountDomainService->register($email, 'password123');

        $accountId = $account->getId();
        self::assertNotNull($accountId, 'User registration should return a user ID');

        return $accountId;
    }

    private function getOrganizationForUser(string $userId): Organization
    {
        $orgId = $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($userId);
        self::assertNotNull($orgId, 'User should have an active organization');

        $organization = $this->orgRepository->findById($orgId);
        self::assertNotNull($organization, 'Organization should exist');

        return $organization;
    }

    public function testEmailCanBeInvitedToOrganizationReturnsTrueForNewEmail(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $newEmail  = 'newinvite-' . uniqid() . '@example.com';
        $canInvite = $this->orgService->emailCanBeInvitedToOrganization($newEmail, $organization);

        $this->assertTrue($canInvite);
    }

    public function testEmailCanBeInvitedReturnsFalseForOwner(): void
    {
        $ownerUserId = $this->createTestUser('owner');
        $ownerEmail  = $this->accountFacade->getAccountCoreEmailById($ownerUserId);
        self::assertNotNull($ownerEmail, 'Owner email should exist');

        $organization = $this->getOrganizationForUser($ownerUserId);

        $canInvite = $this->orgService->emailCanBeInvitedToOrganization($ownerEmail, $organization);

        $this->assertFalse($canInvite);
    }

    public function testEmailCanBeInvitedReturnsFalseForExistingMember(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $memberUserId = $this->createTestUser('member');
        $memberEmail  = $this->accountFacade->getAccountCoreEmailById($memberUserId);
        self::assertNotNull($memberEmail, 'Member email should exist');

        $organization = $this->getOrganizationForUser($ownerUserId);

        // Add member to organization
        $this->orgRepository->addUserToOrganization($memberUserId, $organization->getId());

        $canInvite = $this->orgService->emailCanBeInvitedToOrganization($memberEmail, $organization);

        $this->assertFalse($canInvite);
    }

    public function testInviteEmailCreatesInvitation(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $inviteEmail = 'toinvite-' . uniqid() . '@example.com';
        $invitation  = $this->orgService->inviteEmailToOrganization($inviteEmail, $organization);

        $this->assertNotNull($invitation);
        $this->assertSame($inviteEmail, $invitation->getEmail());
        $this->assertSame($organization->getId(), $invitation->getOrganization()->getId());
    }

    public function testInviteEmailReturnsNullForNonInvitableEmail(): void
    {
        $ownerUserId = $this->createTestUser('owner');
        $ownerEmail  = $this->accountFacade->getAccountCoreEmailById($ownerUserId);
        self::assertNotNull($ownerEmail, 'Owner email should exist');

        $organization = $this->getOrganizationForUser($ownerUserId);

        $invitation = $this->orgService->inviteEmailToOrganization($ownerEmail, $organization);

        $this->assertNull($invitation);
    }

    public function testInviteEmailNormalizesToLowercase(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $inviteEmail = 'UPPERCASE-' . uniqid() . '@EXAMPLE.COM';
        $invitation  = $this->orgService->inviteEmailToOrganization($inviteEmail, $organization);

        $this->assertNotNull($invitation);
        $this->assertSame(mb_strtolower($inviteEmail), $invitation->getEmail());
    }

    public function testGetPendingInvitationsReturnsInvitations(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $email1 = 'pending1-' . uniqid() . '@example.com';
        $email2 = 'pending2-' . uniqid() . '@example.com';
        $this->orgService->inviteEmailToOrganization($email1, $organization);
        $this->orgService->inviteEmailToOrganization($email2, $organization);

        $pending = $this->orgService->getPendingInvitations($organization);

        $this->assertCount(2, $pending);
        $emails = array_map(fn (Invitation $i): string => $i->getEmail(), $pending);
        $this->assertContains($email1, $emails);
        $this->assertContains($email2, $emails);
    }

    public function testHasPendingInvitationsReturnsTrue(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $this->orgService->inviteEmailToOrganization('hasinvite-' . uniqid() . '@example.com', $organization);

        $this->assertTrue($this->orgService->hasPendingInvitations($organization));
    }

    public function testHasPendingInvitationsReturnsFalseWhenEmpty(): void
    {
        $ownerUserId  = $this->createTestUser();
        $organization = $this->getOrganizationForUser($ownerUserId);

        $this->assertFalse($this->orgService->hasPendingInvitations($organization));
    }

    public function testAcceptInvitationForExistingUser(): void
    {
        $ownerUserId    = $this->createTestUser('owner');
        $existingUserId = $this->createTestUser('existing');
        $existingEmail  = $this->accountFacade->getAccountCoreEmailById($existingUserId);
        self::assertNotNull($existingEmail, 'Existing user email should exist');

        $organization = $this->getOrganizationForUser($ownerUserId);

        // Create invitation for existing user's email
        $invitation = new Invitation($organization, $existingEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        // Accept invitation
        $resultUserId = $this->orgService->acceptInvitation($invitation, null);

        $this->assertSame($existingUserId, $resultUserId);
        $this->assertTrue($this->orgRepository->userHasJoinedOrganization($existingUserId, $organization->getId()));
    }

    public function testAcceptInvitationCreatesNewUser(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $organization = $this->getOrganizationForUser($ownerUserId);

        $newEmail   = 'newuser-' . uniqid() . '@example.com';
        $invitation = new Invitation($organization, $newEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        // Accept invitation - should create new user
        $newUserId = $this->orgService->acceptInvitation($invitation, null);

        $this->assertNotNull($newUserId);
        $this->assertTrue($this->accountFacade->accountCoreWithIdExists($newUserId));
        $this->assertTrue($this->orgRepository->userHasJoinedOrganization($newUserId, $organization->getId()));
    }

    public function testAcceptInvitationAddsToDefaultGroup(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $organization = $this->getOrganizationForUser($ownerUserId);

        $newEmail   = 'defaultgroup-' . uniqid() . '@example.com';
        $invitation = new Invitation($organization, $newEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $newUserId = $this->orgService->acceptInvitation($invitation, null);
        self::assertNotNull($newUserId, 'New user ID should be returned');

        $defaultGroup = $this->orgService->getDefaultGroupForNewMembers($organization);
        $this->assertTrue($this->orgRepository->userIsMemberOfGroup($newUserId, $defaultGroup->getId()));
    }

    public function testAcceptInvitationRemovesInvitation(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $organization = $this->getOrganizationForUser($ownerUserId);

        $newEmail   = 'removeinv-' . uniqid() . '@example.com';
        $invitation = new Invitation($organization, $newEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        $invitationId = $invitation->getId();

        $this->orgService->acceptInvitation($invitation, null);

        // Invitation should be removed
        $foundInvitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);
        $this->assertNull($foundInvitation);
    }

    public function testAcceptInvitationForAlreadyMemberCleansUpInvitation(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $memberUserId = $this->createTestUser('member');
        $memberEmail  = $this->accountFacade->getAccountCoreEmailById($memberUserId);
        self::assertNotNull($memberEmail, 'Member email should exist');

        $organization = $this->getOrganizationForUser($ownerUserId);

        // First add user to organization
        $this->orgRepository->addUserToOrganization($memberUserId, $organization->getId());

        // Then create invitation (shouldn't happen in practice, but test the edge case)
        $invitation = new Invitation($organization, $memberEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        $invitationId = $invitation->getId();

        // Accept invitation - should just clean up
        $resultUserId = $this->orgService->acceptInvitation($invitation, null);

        $this->assertSame($memberUserId, $resultUserId);
        $foundInvitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);
        $this->assertNull($foundInvitation);
    }

    public function testAcceptInvitationSetsMustSetPasswordForNewUser(): void
    {
        $ownerUserId  = $this->createTestUser('owner');
        $organization = $this->getOrganizationForUser($ownerUserId);

        $newEmail   = 'mustsetpw-' . uniqid() . '@example.com';
        $invitation = new Invitation($organization, $newEmail);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $newUserId = $this->orgService->acceptInvitation($invitation, null);
        self::assertNotNull($newUserId);

        // The new user should have mustSetPassword = true
        $this->assertTrue($this->accountFacade->mustSetPassword($newEmail));
    }
}
