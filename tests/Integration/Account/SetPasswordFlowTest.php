<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Domain\Service\AccountDomainService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the password setting flow, particularly for users created via invitation.
 */
final class SetPasswordFlowTest extends WebTestCase
{
    private AccountDomainService $accountService;
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var AccountDomainService $service */
        $service              = $container->get(AccountDomainService::class);
        $this->accountService = $service;

        /** @var EntityManagerInterface $em */
        $em                  = $container->get(EntityManagerInterface::class);
        $this->entityManager = $em;
    }

    /**
     * Functional test: Calls the actual controller endpoint to verify that
     * the mustSetPassword flag is correctly persisted after setting a password.
     */
    public function testSetPasswordControllerPersistsMustSetPasswordFlag(): void
    {
        // Step 1: Create an account with mustSetPassword = true (simulating invitation flow)
        $email   = 'controller-test-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, null, true);

        $this->assertTrue($account->getMustSetPassword(), 'Account should have mustSetPassword=true after invitation registration');

        // Step 2: Log in as the user and call the set-password endpoint
        $this->client->loginUser($account);

        $this->client->request('POST', '/en/account/set-password', [
            'password'         => 'newSecurePassword123',
            'password_confirm' => 'newSecurePassword123',
        ]);

        // Should redirect to dashboard on success
        $this->assertResponseRedirects('/en/account/dashboard');

        // Step 3: Clear the entity manager to force a fresh load from database
        $this->entityManager->clear();

        // Step 4: Reload the account from database
        $reloadedAccount = $this->accountService->findByEmail($email);

        // Step 5: Verify the flag was actually persisted
        $this->assertNotNull($reloadedAccount, 'Account should exist in database');
        $this->assertFalse(
            $reloadedAccount->getMustSetPassword(),
            'mustSetPassword flag should be persisted as false after calling the set-password controller.'
        );
    }

    /**
     * Test that set password page redirects to dashboard if user doesn't need to set password.
     */
    public function testSetPasswordRedirectsIfNotRequired(): void
    {
        $email   = 'no-set-password-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, 'password123', false);

        $this->assertFalse($account->getMustSetPassword());

        $this->client->loginUser($account);
        $this->client->request('GET', '/en/account/set-password');

        $this->assertResponseRedirects('/en/account/dashboard');
    }

    /**
     * Test that mismatched passwords show an error.
     */
    public function testSetPasswordShowsErrorOnMismatch(): void
    {
        $email   = 'mismatch-test-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, null, true);

        $this->client->loginUser($account);

        $this->client->request('POST', '/en/account/set-password', [
            'password'         => 'newSecurePassword123',
            'password_confirm' => 'differentPassword456',
        ]);

        // Should stay on the same page (re-render)
        $this->assertResponseIsSuccessful();
    }
}
