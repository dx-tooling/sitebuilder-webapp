<?php

declare(strict_types=1);

namespace App\Tests\Application\Account;

use App\Account\Domain\Service\AccountDomainService;
use App\Account\Infrastructure\Security\FunnyGreetingProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    private FunnyGreetingProvider $funnyGreetingProvider;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var AccountDomainService $accountDomainService */
        $accountDomainService       = $container->get(AccountDomainService::class);
        $this->accountDomainService = $accountDomainService;

        /** @var FunnyGreetingProvider $funnyGreetingProvider */
        $funnyGreetingProvider       = $container->get(FunnyGreetingProvider::class);
        $this->funnyGreetingProvider = $funnyGreetingProvider;

        /** @var TranslatorInterface $translator */
        $translator       = $container->get(TranslatorInterface::class);
        $this->translator = $translator;
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
        $this->assertGreetingOccurrences('en', 1);

        // Flash should only be shown on the first page after login.
        $this->client->request('GET', '/en/projects');
        self::assertResponseIsSuccessful();
        $this->assertGreetingOccurrences('en', 0);
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

    public function testSignInShowsLocalizedGreetingInGerman(): void
    {
        // Arrange: Create a test user
        $email         = 'test-signin-de-' . uniqid() . '@example.com';
        $plainPassword = 'test-password-123';

        $this->createTestUser($email, $plainPassword);

        // Act: Submit the German login form
        $crawler = $this->client->request('GET', '/de/account/sign-in');

        $form = $crawler->selectButton('Weiter')->form([
            'email'    => $email,
            'password' => $plainPassword,
        ]);

        $this->client->submit($form);

        // Assert: Redirect and localized greeting on the first rendered page.
        self::assertResponseRedirects('/de/projects');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ihre Projekte');
        $this->assertGreetingOccurrences('de', 1);
    }

    private function assertGreetingOccurrences(string $locale, int $expectedOccurrences): void
    {
        $expectedGreetings = $this->getLocalizedGreetings($locale);
        $responseContent   = $this->client->getResponse()->getContent();
        self::assertIsString($responseContent);

        $actualOccurrences = 0;
        foreach ($expectedGreetings as $expectedGreeting) {
            $actualOccurrences += substr_count($responseContent, $expectedGreeting);
        }

        self::assertSame($expectedOccurrences, $actualOccurrences);
    }

    /**
     * @return list<string>
     */
    private function getLocalizedGreetings(string $locale): array
    {
        $greetingKeys       = $this->funnyGreetingProvider->getAvailableGreetingKeys();
        $localizedGreetings = [];
        foreach ($greetingKeys as $greetingKey) {
            $localizedGreetings[] = $this->translator->trans($greetingKey, [], null, $locale);
        }

        return $localizedGreetings;
    }

    private function createTestUser(string $email, string $plainPassword): void
    {
        // Use proper registration to trigger organization creation via event
        $this->accountDomainService->register($email, $plainPassword);
    }
}
