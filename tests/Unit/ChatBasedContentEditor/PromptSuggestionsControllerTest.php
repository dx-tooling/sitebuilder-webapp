<?php

declare(strict_types=1);

namespace Tests\Unit\ChatBasedContentEditor;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\AccountInfoDto;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Presentation\Controller\PromptSuggestionsController;
use App\ChatBasedContentEditor\Presentation\Service\PromptSuggestionsService;
use App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function file_put_contents;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;

final class PromptSuggestionsControllerTest extends TestCase
{
    private const string CONVERSATION_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const string WORKSPACE_ID    = 'workspace-1';
    private const string USER_ID         = 'user-123';
    private const string USER_EMAIL      = 'user@example.com';

    private string $workspacePath;
    private EntityManagerInterface&MockObject $entityManager;
    private AccountFacadeInterface&MockObject $accountFacade;
    private WorkspaceMgmtFacadeInterface&MockObject $workspaceMgmtFacade;
    private PromptSuggestionsController $controller;

    protected function setUp(): void
    {
        // Create a real temp workspace with suggestion file
        $this->workspacePath = sys_get_temp_dir() . '/prompt-suggestions-test-' . uniqid();
        mkdir($this->workspacePath . '/.sitebuilder', 0755, true);
        file_put_contents(
            $this->workspacePath . '/.sitebuilder/prompt-suggestions.md',
            "Existing suggestion\n",
        );

        $this->entityManager       = $this->createMock(EntityManagerInterface::class);
        $this->accountFacade       = $this->createMock(AccountFacadeInterface::class);
        $this->workspaceMgmtFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);

        // Use the real PromptSuggestionsService (it is final readonly, cannot be mocked)
        $promptSuggestionsService = new PromptSuggestionsService();

        $this->controller = new PromptSuggestionsController(
            $this->entityManager,
            $this->accountFacade,
            $this->workspaceMgmtFacade,
            $promptSuggestionsService,
        );

        // Set up container mock for CSRF validation and json() method
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static function (string $id): bool {
                return $id === 'security.csrf.token_manager';
            });
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($csrfTokenManager): ?object {
                if ($id === 'security.csrf.token_manager') {
                    return $csrfTokenManager;
                }

                return null;
            });

        $this->controller->setContainer($container);

        // Set up default mocks for the happy path
        $conversation = $this->createConversation(
            self::CONVERSATION_ID,
            self::WORKSPACE_ID,
            self::USER_ID,
            ConversationStatus::ONGOING,
        );
        $this->entityManager->method('find')
            ->with(Conversation::class, self::CONVERSATION_ID)
            ->willReturn($conversation);

        $this->accountFacade->method('getAccountInfoByEmail')
            ->with(self::USER_EMAIL)
            ->willReturn(new AccountInfoDto(
                self::USER_ID,
                self::USER_EMAIL,
                ['ROLE_USER'],
                DateAndTimeService::getDateTimeImmutable(),
            ));

        $this->workspaceMgmtFacade->method('getWorkspaceById')
            ->with(self::WORKSPACE_ID)
            ->willReturn(new WorkspaceInfoDto(
                self::WORKSPACE_ID,
                'project-1',
                'Test Project',
                WorkspaceStatus::IN_CONVERSATION,
                'main',
                $this->workspacePath,
                null,
                null,
            ));
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $suggestionsFile = $this->workspacePath . '/.sitebuilder/prompt-suggestions.md';
        if (file_exists($suggestionsFile)) {
            unlink($suggestionsFile);
        }

        $sitebuilderDir = $this->workspacePath . '/.sitebuilder';
        if (is_dir($sitebuilderDir)) {
            rmdir($sitebuilderDir);
        }

        if (is_dir($this->workspacePath)) {
            rmdir($this->workspacePath);
        }
    }

    // ---------------------------------------------------------------
    // Happy Path — commitAndPush is called with correct parameters
    // ---------------------------------------------------------------

    public function testCreateCallsCommitAndPushWithCorrectParameters(): void
    {
        $this->workspaceMgmtFacade->expects($this->once())
            ->method('commitAndPush')
            ->with(self::WORKSPACE_ID, 'Add prompt suggestion', self::USER_EMAIL, self::CONVERSATION_ID);

        $response = $this->controller->create(
            self::CONVERSATION_ID,
            $this->createJsonRequest(Request::METHOD_POST, ['text' => 'New suggestion']),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertSame(['Existing suggestion', 'New suggestion'], $data['suggestions']);
    }

    public function testUpdateCallsCommitAndPushWithCorrectParameters(): void
    {
        $this->workspaceMgmtFacade->expects($this->once())
            ->method('commitAndPush')
            ->with(self::WORKSPACE_ID, 'Update prompt suggestion', self::USER_EMAIL, self::CONVERSATION_ID);

        $response = $this->controller->update(
            self::CONVERSATION_ID,
            0,
            $this->createJsonRequest(Request::METHOD_PUT, ['text' => 'Updated text']),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertSame(['Updated text'], $data['suggestions']);
    }

    public function testDeleteCallsCommitAndPushWithCorrectParameters(): void
    {
        $this->workspaceMgmtFacade->expects($this->once())
            ->method('commitAndPush')
            ->with(self::WORKSPACE_ID, 'Remove prompt suggestion', self::USER_EMAIL, self::CONVERSATION_ID);

        $response = $this->controller->delete(
            self::CONVERSATION_ID,
            0,
            $this->createDeleteRequest(),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertSame([], $data['suggestions']);
    }

    // ---------------------------------------------------------------
    // Error Handling — commitAndPush failures return HTTP 500
    // ---------------------------------------------------------------

    public function testCreateReturns500WhenCommitAndPushFails(): void
    {
        $this->workspaceMgmtFacade->method('commitAndPush')
            ->willThrowException(new RuntimeException('Git push failed'));

        $response = $this->controller->create(
            self::CONVERSATION_ID,
            $this->createJsonRequest(Request::METHOD_POST, ['text' => 'New suggestion']),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Git push failed', $data['error']);
    }

    public function testUpdateReturns500WhenCommitAndPushFails(): void
    {
        $this->workspaceMgmtFacade->method('commitAndPush')
            ->willThrowException(new RuntimeException('Network error'));

        $response = $this->controller->update(
            self::CONVERSATION_ID,
            0,
            $this->createJsonRequest(Request::METHOD_PUT, ['text' => 'Updated']),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Network error', $data['error']);
    }

    public function testDeleteReturns500WhenCommitAndPushFails(): void
    {
        $this->workspaceMgmtFacade->method('commitAndPush')
            ->willThrowException(new RuntimeException('Permission denied'));

        $response = $this->controller->delete(
            self::CONVERSATION_ID,
            0,
            $this->createDeleteRequest(),
            $this->createUser(),
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = $this->decodeResponse($response);
        self::assertIsString($data['error']);
        self::assertStringContainsString('Permission denied', $data['error']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function createConversation(
        string             $id,
        string             $workspaceId,
        string             $userId,
        ConversationStatus $status,
    ): Conversation {
        $conversation = new Conversation($workspaceId, $userId, '/path/to/workspace');
        $conversation->setStatus($status);

        $reflection = new ReflectionClass($conversation);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($conversation, $id);

        return $conversation;
    }

    private function createUser(): UserInterface
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')
            ->willReturn(self::USER_EMAIL);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createJsonRequest(string $method, array $body): Request
    {
        return new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_METHOD'    => $method,
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X_CSRF_Token' => 'valid-token',
            ],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function createDeleteRequest(): Request
    {
        return new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REQUEST_METHOD'    => Request::METHOD_DELETE,
                'HTTP_X_CSRF_Token' => 'valid-token',
            ],
        );
    }
}
