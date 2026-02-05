<?php

declare(strict_types=1);

namespace App\Tests\Application\Organization;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Facade\AccountFacadeInterface;
use App\Organization\Domain\Entity\Invitation;
use App\Organization\Infrastructure\Repository\OrganizationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AcceptInvitationFlowTest extends WebTestCase
{
    private function getCsrfToken(string $tokenId): string
    {
        $container = static::getContainer();

        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = $container->get(CsrfTokenManagerInterface::class);

        return $csrfTokenManager->getToken($tokenId)->getValue();
    }

    public function testAcceptInvitationCreatesUserAndRedirectsToSetPassword(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade = $container->get(AccountFacadeInterface::class);

        /** @var AccountDomainService $accountService */
        $accountService = $container->get(AccountDomainService::class);

        /** @var OrganizationRepositoryInterface $organizationRepository */
        $organizationRepository = $container->get(OrganizationRepositoryInterface::class);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        // Create owner and their organization
        $ownerEmail   = 'owner-' . uniqid() . '@example.com';
        $ownerAccount = $accountService->register($ownerEmail, 'password123');
        $ownerUserId  = $ownerAccount->getId();
        self::assertNotNull($ownerUserId, 'Owner registration must return a userId');

        $ownerOrgId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId, 'Owner must have an active organization');

        $organization = $organizationRepository->findById($ownerOrgId);
        self::assertNotNull($organization, 'Organization must exist');

        // Create invitation for a new user
        $inviteeEmail = 'invitee-' . uniqid() . '@example.com';
        $invitation   = new Invitation($organization, $inviteeEmail);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $invitationId = $invitation->getId();
        self::assertNotNull($invitationId);

        // GET the invitation page
        $client->request('GET', "/en/organization/invitation/{$invitationId}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');

        // POST to accept the invitation
        $client->request('POST', "/en/organization/invitation/{$invitationId}", [
            '_csrf_token' => $this->getCsrfToken('accept_invitation'),
        ]);
        $this->assertResponseRedirects('/en/account/set-password');

        // Verify user was created
        $inviteeUserId = $accountFacade->getAccountCoreIdByEmail($inviteeEmail);
        self::assertNotNull($inviteeUserId, 'Invitee account must be created');

        // Verify user joined the organization
        $this->assertTrue(
            $organizationRepository->userHasJoinedOrganization($inviteeUserId, $ownerOrgId),
            'Invitee must be a member of the inviting organization'
        );
    }

    public function testAcceptInvitationForExistingUserRedirectsToDashboard(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade = $container->get(AccountFacadeInterface::class);

        /** @var AccountDomainService $accountService */
        $accountService = $container->get(AccountDomainService::class);

        /** @var OrganizationRepositoryInterface $organizationRepository */
        $organizationRepository = $container->get(OrganizationRepositoryInterface::class);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        // Create owner and their organization
        $ownerEmail   = 'owner2-' . uniqid() . '@example.com';
        $ownerAccount = $accountService->register($ownerEmail, 'password123');
        $ownerUserId  = $ownerAccount->getId();
        self::assertNotNull($ownerUserId);

        $ownerOrgId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId);

        $organization = $organizationRepository->findById($ownerOrgId);
        self::assertNotNull($organization);

        // Create an existing user (not the owner)
        $existingEmail   = 'existing-' . uniqid() . '@example.com';
        $existingAccount = $accountService->register($existingEmail, 'password456');
        $existingUserId  = $existingAccount->getId();
        self::assertNotNull($existingUserId);

        // Create invitation for the existing user
        $invitation = new Invitation($organization, $existingEmail);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $invitationId = $invitation->getId();
        self::assertNotNull($invitationId);

        // GET the invitation page
        $client->request('GET', "/en/organization/invitation/{$invitationId}");
        $this->assertResponseIsSuccessful();

        // POST to accept the invitation
        $client->request('POST', "/en/organization/invitation/{$invitationId}", [
            '_csrf_token' => $this->getCsrfToken('accept_invitation'),
        ]);

        // Existing users don't have mustSetPassword=true, so they go to dashboard
        $this->assertResponseRedirects('/en/account/dashboard');

        // Verify user joined the organization
        $this->assertTrue(
            $organizationRepository->userHasJoinedOrganization($existingUserId, $ownerOrgId),
            'Existing user must be a member of the inviting organization'
        );
    }

    public function testAcceptInvitationRejectsInvalidCsrf(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        /** @var AccountFacadeInterface $accountFacade */
        $accountFacade = $container->get(AccountFacadeInterface::class);

        /** @var AccountDomainService $accountService */
        $accountService = $container->get(AccountDomainService::class);

        /** @var OrganizationRepositoryInterface $organizationRepository */
        $organizationRepository = $container->get(OrganizationRepositoryInterface::class);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = $container->get(UrlGeneratorInterface::class);

        // Create owner and their organization
        $ownerEmail   = 'owner-invalid-' . uniqid() . '@example.com';
        $ownerAccount = $accountService->register($ownerEmail, 'password123');
        $ownerUserId  = $ownerAccount->getId();
        self::assertNotNull($ownerUserId);

        $ownerOrgId = $accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($ownerUserId);
        self::assertNotNull($ownerOrgId);

        $organization = $organizationRepository->findById($ownerOrgId);
        self::assertNotNull($organization);

        // Create invitation for a new user
        $inviteeEmail = 'invitee-invalid-' . uniqid() . '@example.com';
        $invitation   = new Invitation($organization, $inviteeEmail);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $invitationId = $invitation->getId();
        self::assertNotNull($invitationId);

        // GET the invitation page
        $client->request('GET', "/en/organization/invitation/{$invitationId}");
        $this->assertResponseIsSuccessful();

        // POST with invalid CSRF token
        $client->request('POST', "/en/organization/invitation/{$invitationId}", [
            '_csrf_token' => 'invalid-token',
        ]);

        $this->assertResponseRedirects($urlGenerator->generate('static_pages.presentation.homepage'));

        $inviteeUserId = $accountFacade->getAccountCoreIdByEmail($inviteeEmail);
        self::assertNull($inviteeUserId, 'Invitee account must not be created with invalid CSRF.');
    }
}
