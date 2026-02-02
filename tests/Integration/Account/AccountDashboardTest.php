<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Domain\Service\AccountDomainService;
use App\Tests\Support\SecurityUserLoginTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the account dashboard page.
 *
 * These tests verify that the dashboard renders correctly with all user data.
 * This catches issues where SecurityUser might be missing properties that
 * templates expect (e.g., createdAt for "member since" display).
 */
final class AccountDashboardTest extends WebTestCase
{
    use SecurityUserLoginTrait;

    private AccountDomainService $accountService;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var AccountDomainService $service */
        $service              = $container->get(AccountDomainService::class);
        $this->accountService = $service;
    }

    /**
     * Test that the dashboard renders successfully for an authenticated user.
     *
     * This test catches missing properties on SecurityUser that templates depend on,
     * such as createdAt for the "member since" display.
     */
    public function testDashboardRendersSuccessfully(): void
    {
        $email   = 'dashboard-test-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, 'password123', false);

        $this->loginAsUser($this->client, $account);

        $crawler = $this->client->request('GET', '/en/account/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Your account');
    }

    /**
     * Test that the dashboard displays the user's email address.
     */
    public function testDashboardDisplaysUserEmail(): void
    {
        $email   = 'email-display-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, 'password123', false);

        $this->loginAsUser($this->client, $account);

        $this->client->request('GET', '/en/account/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $email);
    }

    /**
     * Test that the dashboard displays the member since date.
     *
     * This specifically tests that SecurityUser has createdAt available,
     * which the template uses to show when the account was created.
     */
    public function testDashboardDisplaysMemberSinceDate(): void
    {
        $email   = 'member-since-' . uniqid() . '@example.com';
        $account = $this->accountService->register($email, 'password123', false);

        $this->loginAsUser($this->client, $account);

        $this->client->request('GET', '/en/account/dashboard');

        $this->assertResponseIsSuccessful();
        // The createdAt date should be displayed in the format Y-m-d H:i:s
        // We check for the current year as a sanity check
        $this->assertSelectorTextContains('body', (string) date('Y'));
    }
}
