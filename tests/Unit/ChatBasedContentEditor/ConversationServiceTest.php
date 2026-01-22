<?php

declare(strict_types=1);

namespace Tests\Unit\ChatBasedContentEditor;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\AccountInfoDto;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Service\ConversationService;
use App\ChatBasedContentEditor\Infrastructure\Service\ConversationUrlServiceInterface;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ConversationServiceTest extends TestCase
{
    private function createAccountFacade(string $userId = 'user-123', string $email = 'user@example.com'): AccountFacadeInterface
    {
        $accountFacade = $this->createMock(AccountFacadeInterface::class);
        $accountFacade->method('getAccountInfoById')
            ->with($userId)
            ->willReturn(new AccountInfoDto($userId, $email, ['ROLE_USER'], DateAndTimeService::getDateTimeImmutable()));

        return $accountFacade;
    }

    private function createConversationUrlService(): ConversationUrlServiceInterface
    {
        $urlService = $this->createMock(ConversationUrlServiceInterface::class);
        $urlService->method('getConversationUrl')
            ->willReturnCallback(static fn (string $id): string => 'https://sitebuilder.example.com/conversation/' . $id);

        return $urlService;
    }

    public function testFinishConversationThrowsWhenConversationNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');

        $service->finishConversation('non-existent-id', 'user-123');
    }

    public function testFinishConversationThrowsWhenUserIsNotOwner(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'owner-user', ConversationStatus::ONGOING);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the conversation owner can perform this action');

        $service->finishConversation('conv-1', 'different-user');
    }

    public function testFinishConversationThrowsWhenConversationNotOngoing(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'user-123', ConversationStatus::FINISHED);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation is not ongoing');

        $service->finishConversation('conv-1', 'user-123');
    }

    public function testFinishConversationCommitsAndTransitionsWorkspace(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'user-123', ConversationStatus::ONGOING);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);
        $entityManager->expects($this->once())->method('flush');

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $workspaceFacade->expects($this->once())
            ->method('commitAndPush')
            ->with('workspace-1', 'Auto-commit on conversation finish', 'user@example.com', 'conv-1', 'https://sitebuilder.example.com/conversation/conv-1');
        $workspaceFacade->expects($this->once())
            ->method('transitionToAvailableForConversation')
            ->with('workspace-1');

        $accountFacade = $this->createAccountFacade('user-123', 'user@example.com');
        $urlService    = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);
        $service->finishConversation('conv-1', 'user-123');

        self::assertSame(ConversationStatus::FINISHED, $conversation->getStatus());
    }

    public function testSendToReviewThrowsWhenConversationNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');

        $service->sendToReview('non-existent-id', 'user-123');
    }

    public function testSendToReviewThrowsWhenUserIsNotOwner(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'owner-user', ConversationStatus::ONGOING);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only the conversation owner can perform this action');

        $service->sendToReview('conv-1', 'different-user');
    }

    public function testSendToReviewThrowsWhenConversationNotOngoing(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'user-123', ConversationStatus::FINISHED);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $accountFacade   = $this->createMock(AccountFacadeInterface::class);
        $urlService      = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conversation is not ongoing');

        $service->sendToReview('conv-1', 'user-123');
    }

    public function testSendToReviewCommitsTransitionsAndReturnsPrUrl(): void
    {
        $conversation = $this->createConversation('conv-1', 'workspace-1', 'user-123', ConversationStatus::ONGOING);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($conversation);
        $entityManager->expects($this->once())->method('flush');

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $workspaceFacade->expects($this->once())
            ->method('commitAndPush')
            ->with('workspace-1', 'Auto-commit before review', 'user@example.com', 'conv-1', 'https://sitebuilder.example.com/conversation/conv-1');
        $workspaceFacade->expects($this->once())
            ->method('transitionToInReview')
            ->with('workspace-1');
        $workspaceFacade->expects($this->once())
            ->method('ensurePullRequest')
            ->with('workspace-1', 'conv-1', 'https://sitebuilder.example.com/conversation/conv-1', 'user@example.com')
            ->willReturn('https://github.com/org/repo/pull/123');

        $accountFacade = $this->createAccountFacade('user-123', 'user@example.com');
        $urlService    = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);
        $prUrl   = $service->sendToReview('conv-1', 'user-123');

        self::assertSame(ConversationStatus::FINISHED, $conversation->getStatus());
        self::assertSame('https://github.com/org/repo/pull/123', $prUrl);
    }

    public function testStartOrResumeConversationReturnsExistingConversationWhenWorkspaceInConversation(): void
    {
        // Scenario: User navigated away from a conversation and came back.
        // The workspace is still IN_CONVERSATION and user has an ongoing conversation.
        // The service should return the existing conversation without creating a new one.

        $existingConversation = $this->createConversation(
            'existing-conv-id',
            'workspace-1',
            'user-123',
            ConversationStatus::ONGOING
        );

        $workspaceInfo = new \App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto(
            'workspace-1',
            'project-1',
            'Test Project',
            \App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus::IN_CONVERSATION,
            'feature-branch',
            '/path/to/workspace',
            'https://github.com/org/repo/tree/feature-branch',
            null
        );

        // Mock QueryBuilder chain for findOngoingConversation
        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOneOrNullResult'])
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn($existingConversation);

        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $workspaceFacade->method('getWorkspaceForProject')
            ->with('project-1')
            ->willReturn($workspaceInfo);

        // Key assertion: transitionToInConversation should NOT be called
        // because we found an existing conversation for this user
        $workspaceFacade->expects($this->never())->method('transitionToInConversation');

        $accountFacade = $this->createMock(AccountFacadeInterface::class);
        $urlService    = $this->createConversationUrlService();

        $service = new ConversationService($entityManager, $workspaceFacade, $accountFacade, $urlService);
        $result  = $service->startOrResumeConversation('project-1', 'user-123');

        self::assertSame('existing-conv-id', $result->id);
        self::assertSame('workspace-1', $result->workspaceId);
        self::assertSame('user-123', $result->userId);
        self::assertSame(ConversationStatus::ONGOING, $result->status);
    }

    // Note: Testing new conversation creation requires integration tests due to
    // Doctrine ID generation. The test above covers the critical fix for resuming
    // existing conversations when workspace is IN_CONVERSATION.

    /**
     * Helper to create a Conversation with reflection to set the ID.
     */
    private function createConversation(
        string             $id,
        string             $workspaceId,
        string             $userId,
        ConversationStatus $status
    ): Conversation {
        $conversation = new Conversation($workspaceId, $userId, '/path/to/workspace');
        $conversation->setStatus($status);

        // Use reflection to set the ID since it's normally set by Doctrine
        $reflection = new ReflectionClass($conversation);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($conversation, $id);

        return $conversation;
    }
}
