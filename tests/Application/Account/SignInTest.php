<?php

declare(strict_types=1);

namespace App\Tests\Application\Account;

use App\Account\Domain\Service\AccountDomainService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests the sign-in flow to prevent regressions in form field configuration.
 *
 * This test verifies that the login form correctly accepts 'email' and 'password'
 * field names (as opposed to Symfony's default '_username' and '_password').
 */
final class SignInTest extends WebTestCase
{
    private KernelBrowser $client;
    private AccountDomainService $accountDomainService;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;
    }

    public function testSignInWithValidCredentialsRedirectsToProjects(): void
    {
        // Arrange: Create a test user
        $email         = 'test-signin-' . uniqid() . '@example.com';
        $plainPassword = 'test-password-123';

        $this->createTestUser($email, $plainPassword);

        // Act: Submit the login form
        $crawler = $this->client->request('GET', '/en/account/sign-in');

        $form = $crawler->selectButton('Continue')->form([
            'email'    => $email,
            'password' => $plainPassword,
        ]);

        $this->client->submit($form);

        // Assert: User is redirected to projects page (successful login)
        self::assertResponseRedirects('/en/projects');

        // Follow redirect and verify we're authenticated
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your projects');
    }

    public function testSignInWithInvalidCredentialsShowsError(): void
    {
        // Arrange: Create a test user
        $email         = 'test-invalid-' . uniqid() . '@example.com';
        $plainPassword = 'correct-password';

        $this->createTestUser($email, $plainPassword);

        // Act: Submit the login form with wrong password
        $crawler = $this->client->request('GET', '/en/account/sign-in');

        $form = $crawler->selectButton('Continue')->form([
            'email'    => $email,
            'password' => 'wrong-password',
        ]);

        $this->client->submit($form);

        // Assert: User is redirected back to login (failed login)
        self::assertResponseRedirects('/en/account/sign-in');

        // Follow redirect and verify error is shown
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.etfswui-alert-danger');
    }

    public function testSignInFormHasCorrectFieldNames(): void
    {
        // Act: Request the login page
        $crawler = $this->client->request('GET', '/en/account/sign-in');

        // Assert: Form has 'email' and 'password' fields (not '_username' and '_password')
        self::assertCount(1, $crawler->filter('input[name="email"]'));
        self::assertCount(1, $crawler->filter('input[name="password"]'));
        self::assertCount(0, $crawler->filter('input[name="_username"]'));
        self::assertCount(0, $crawler->filter('input[name="_password"]'));
    }

    private function createTestUser(string $email, string $plainPassword): void
    {
        // Use proper registration to trigger organization creation via event
        $this->accountDomainService->register($email, $plainPassword);
    }
}
