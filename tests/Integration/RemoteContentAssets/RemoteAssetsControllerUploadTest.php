<?php

declare(strict_types=1);

namespace App\Tests\Integration\RemoteContentAssets;

use App\Account\Domain\Entity\AccountCore;
use App\Account\Infrastructure\Security\SecurityUserProvider;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Throwable;

/**
 * Integration tests for the RemoteAssetsController::upload() endpoint.
 *
 * Tests validation logic (CSRF, file presence, MIME type), success flow, and S3 error handling.
 * The RemoteContentAssetsFacadeInterface is mocked to avoid real S3 calls.
 */
final class RemoteAssetsControllerUploadTest extends WebTestCase
{
    private const string CSRF_TOKEN_VALUE = 'test-csrf-token-for-upload-validation';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->cleanupTestData();
    }

    private function cleanupTestData(): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeStatement('DELETE FROM conversations');
            $connection->executeStatement('DELETE FROM workspaces');
            $connection->executeStatement('DELETE FROM projects');
            $connection->executeStatement('DELETE FROM account_cores');
        } catch (Throwable) {
            // Tables may not exist yet on first run
        }
    }

    // ==========================================
    // CSRF Validation
    // ==========================================

    public function testUploadReturns403WhenCsrfTokenIsInvalid(): void
    {
        $user    = $this->createAndLoginUser();
        $project = $this->createProjectWithS3Config();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->client->request(
            'POST',
            '/en/api/projects/' . $projectId . '/remote-assets/upload',
            ['_csrf_token' => 'invalid-token', 'workspace_id' => 'ws-123']
        );

        self::assertResponseStatusCodeSame(403);

        $response = $this->getJsonResponse();
        self::assertArrayHasKey('error', $response);
        self::assertIsString($response['error']);
        self::assertStringContainsString('CSRF', $response['error']);
    }

    // ==========================================
    // File Validation
    // ==========================================

    public function testUploadReturns400WhenNoFileUploaded(): void
    {
        $user    = $this->createAndLoginUser();
        $project = $this->createProjectWithS3Config();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $this->client->request(
            'POST',
            '/en/api/projects/' . $projectId . '/remote-assets/upload',
            ['_csrf_token' => self::CSRF_TOKEN_VALUE, 'workspace_id' => 'ws-123']
        );

        self::assertResponseStatusCodeSame(400);

        $response = $this->getJsonResponse();
        self::assertArrayHasKey('error', $response);
        self::assertIsString($response['error']);
        self::assertStringContainsString('No file uploaded', $response['error']);
    }

    public function testUploadReturns400WhenMimeTypeNotAllowed(): void
    {
        $user    = $this->createAndLoginUser();
        $project = $this->createProjectWithS3Config();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        // Create a temporary text file (not an allowed image type)
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'This is a plain text file, not an image.');

        $uploadedFile = new UploadedFile($tmpFile, 'document.txt', 'text/plain', null, true);

        try {
            $this->client->request(
                'POST',
                '/en/api/projects/' . $projectId . '/remote-assets/upload',
                ['_csrf_token' => self::CSRF_TOKEN_VALUE, 'workspace_id' => 'ws-123'],
                ['file'        => $uploadedFile]
            );

            self::assertResponseStatusCodeSame(400);

            $response = $this->getJsonResponse();
            self::assertArrayHasKey('error', $response);
            self::assertIsString($response['error']);
            self::assertStringContainsString('File type not allowed', $response['error']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ==========================================
    // Successful Upload
    // ==========================================

    public function testUploadReturnsSuccessWithUrlOnValidJpegUpload(): void
    {
        $user    = $this->createAndLoginUser();
        $project = $this->createProjectWithS3Config();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $expectedUrl = 'https://test-bucket.s3.eu-central-1.amazonaws.com/uploads/20260210/abc123_photo.jpg';

        $mockFacade = $this->mockRemoteContentAssetsFacade();
        $mockFacade->expects($this->once())
            ->method('uploadAsset')
            ->with(
                'test-bucket',
                'eu-central-1',
                'AKIAIOSFODNN7EXAMPLE',
                'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                null,
                'assets',
                'photo.jpg',
                self::callback(fn (string $contents): bool => $contents !== ''),
                'image/jpeg'
            )
            ->willReturn($expectedUrl);

        // Create a minimal JPEG file (valid JPEG header)
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));

        $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);

        $this->client->request(
            'POST',
            '/en/api/projects/' . $projectId . '/remote-assets/upload',
            ['_csrf_token' => self::CSRF_TOKEN_VALUE, 'workspace_id' => 'ws-123'],
            ['file'        => $uploadedFile]
        );

        try {
            self::assertResponseIsSuccessful();

            $response = $this->getJsonResponse();
            self::assertTrue($response['success']);
            self::assertSame($expectedUrl, $response['url']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ==========================================
    // S3 Error Handling
    // ==========================================

    public function testUploadReturns500WhenS3UploadFails(): void
    {
        $user    = $this->createAndLoginUser();
        $project = $this->createProjectWithS3Config();

        $projectId = $project->getId();
        self::assertNotNull($projectId);

        $mockFacade = $this->mockRemoteContentAssetsFacade();
        $mockFacade->method('uploadAsset')
            ->willThrowException(new RuntimeException('Access Denied'));

        // Create a minimal JPEG file
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));

        $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);

        try {
            $this->client->request(
                'POST',
                '/en/api/projects/' . $projectId . '/remote-assets/upload',
                ['_csrf_token' => self::CSRF_TOKEN_VALUE, 'workspace_id' => 'ws-123'],
                ['file'        => $uploadedFile]
            );

            self::assertResponseStatusCodeSame(500);

            $response = $this->getJsonResponse();
            self::assertArrayHasKey('error', $response);
            self::assertIsString($response['error']);
            self::assertStringContainsString('Upload failed', $response['error']);
            self::assertStringContainsString('Access Denied', $response['error']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Create a user, log them in, and set up a session with the CSRF token.
     *
     * This combines user creation, authentication, and CSRF token injection
     * into a single session to ensure the POST requests pass both checks.
     */
    private function createAndLoginUser(): AccountCore
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user           = new AccountCore('upload-test@example.com', '');
        $hashedPassword = $passwordHasher->hashPassword($user, 'password123');
        $user           = new AccountCore('upload-test@example.com', $hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Create a session that contains both the authentication token and the CSRF token
        /** @var \Symfony\Component\HttpFoundation\Session\SessionFactoryInterface $sessionFactory */
        $sessionFactory = $container->get('session.factory');
        $session        = $sessionFactory->createSession();

        // Set authentication token
        /** @var SecurityUserProvider $userProvider */
        $userProvider = $container->get(SecurityUserProvider::class);
        $securityUser = $userProvider->loadUserByIdentifier($user->getEmail());
        $authToken    = new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles());
        $session->set('_security_main', serialize($authToken));

        // Set CSRF token (SessionTokenStorage stores under '_csrf/{tokenId}')
        $session->set('_csrf/remote_asset_upload', self::CSRF_TOKEN_VALUE);

        $session->save();

        // Set the session cookie so the client uses this session
        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);

        return $user;
    }

    private function createProjectWithS3Config(): Project
    {
        $project = new Project(
            'org-test-123',
            'Upload Test Project',
            'https://github.com/org/repo.git',
            'ghp_test_token',
            LlmModelProvider::OpenAI,
            'sk-test-key'
        );

        $project->setS3BucketName('test-bucket');
        $project->setS3Region('eu-central-1');
        $project->setS3AccessKeyId('AKIAIOSFODNN7EXAMPLE');
        $project->setS3SecretAccessKey('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
        $project->setS3KeyPrefix('assets');

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    /**
     * Replace the RemoteContentAssetsFacadeInterface in the container with a mock.
     */
    private function mockRemoteContentAssetsFacade(): RemoteContentAssetsFacadeInterface&MockObject
    {
        $mock = $this->createMock(RemoteContentAssetsFacadeInterface::class);
        static::getContainer()->set(RemoteContentAssetsFacadeInterface::class, $mock);

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonResponse(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true);

        return $decoded;
    }
}
