<?php

declare(strict_types=1);

namespace Tests\Unit\ChatBasedContentEditor;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Service\ConversationService;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ConversationServiceTest extends TestCase
{
    public function testFinishConversationThrowsWhenConversationNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);

        $service = new ConversationService($entityManager, $workspaceFacade);

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

        $service = new ConversationService($entityManager, $workspaceFacade);

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

        $service = new ConversationService($entityManager, $workspaceFacade);

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
            ->with('workspace-1', 'Auto-commit on conversation finish');
        $workspaceFacade->expects($this->once())
            ->method('transitionToAvailableForConversation')
            ->with('workspace-1');

        $service = new ConversationService($entityManager, $workspaceFacade);
        $service->finishConversation('conv-1', 'user-123');

        self::assertSame(ConversationStatus::FINISHED, $conversation->getStatus());
    }

    public function testSendToReviewThrowsWhenConversationNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);

        $service = new ConversationService($entityManager, $workspaceFacade);

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

        $service = new ConversationService($entityManager, $workspaceFacade);

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

        $service = new ConversationService($entityManager, $workspaceFacade);

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
            ->with('workspace-1', 'Auto-commit before review');
        $workspaceFacade->expects($this->once())
            ->method('transitionToInReview')
            ->with('workspace-1');
        $workspaceFacade->expects($this->once())
            ->method('ensurePullRequest')
            ->with('workspace-1')
            ->willReturn('https://github.com/org/repo/pull/123');

        $service = new ConversationService($entityManager, $workspaceFacade);
        $prUrl   = $service->sendToReview('conv-1', 'user-123');

        self::assertSame(ConversationStatus::FINISHED, $conversation->getStatus());
        self::assertSame('https://github.com/org/repo/pull/123', $prUrl);
    }

    // Note: startOrResumeConversation is better tested via integration tests
    // due to the complex Doctrine QueryBuilder interactions involved.

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
