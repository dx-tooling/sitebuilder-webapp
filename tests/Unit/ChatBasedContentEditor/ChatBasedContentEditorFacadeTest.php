<?php

declare(strict_types=1);

namespace App\Tests\Unit\ChatBasedContentEditor;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacade;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class ChatBasedContentEditorFacadeTest extends TestCase
{
    public function testReleaseStaleConversationsFinishesStaleConversationsAndTransitionsWorkspaces(): void
    {
        // Arrange: Create stale conversations with old lastActivityAt
        $conversation1 = $this->createConversation(
            'conv-1',
            'workspace-1',
            'user-1',
            ConversationStatus::ONGOING,
            DateAndTimeService::getDateTimeImmutable()->modify('-10 minutes') // Stale: 10 minutes ago
        );

        $conversation2 = $this->createConversation(
            'conv-2',
            'workspace-2',
            'user-2',
            ConversationStatus::ONGOING,
            DateAndTimeService::getDateTimeImmutable()->modify('-15 minutes') // Stale: 15 minutes ago
        );

        // Mock the query result
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([$conversation1, $conversation2]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $entityManager->expects($this->once())->method('flush');

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $workspaceFacade->expects($this->exactly(2))
            ->method('transitionToAvailableForConversation')
            ->willReturnCallback(function (string $workspaceId): void {
                self::assertContains($workspaceId, ['workspace-1', 'workspace-2']);
            });

        // Act
        $facade = new ChatBasedContentEditorFacade($entityManager, $workspaceFacade);
        $result = $facade->releaseStaleConversations(5);

        // Assert
        self::assertCount(2, $result);
        self::assertContains('workspace-1', $result);
        self::assertContains('workspace-2', $result);
        self::assertSame(ConversationStatus::FINISHED, $conversation1->getStatus());
        self::assertSame(ConversationStatus::FINISHED, $conversation2->getStatus());
    }

    public function testReleaseStaleConversationsReturnsEmptyArrayWhenNoStaleConversations(): void
    {
        // Arrange: No stale conversations
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
        $entityManager->expects($this->once())->method('flush');

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        $workspaceFacade->expects($this->never())->method('transitionToAvailableForConversation');

        // Act
        $facade = new ChatBasedContentEditorFacade($entityManager, $workspaceFacade);
        $result = $facade->releaseStaleConversations(5);

        // Assert
        self::assertSame([], $result);
    }

    public function testReleaseStaleConversationsDeduplicatesWorkspaceIds(): void
    {
        // Arrange: Two conversations for the same workspace (edge case)
        $conversation1 = $this->createConversation(
            'conv-1',
            'workspace-1',
            'user-1',
            ConversationStatus::ONGOING,
            DateAndTimeService::getDateTimeImmutable()->modify('-10 minutes')
        );

        $conversation2 = $this->createConversation(
            'conv-2',
            'workspace-1', // Same workspace
            'user-2',
            ConversationStatus::ONGOING,
            DateAndTimeService::getDateTimeImmutable()->modify('-10 minutes')
        );

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn([$conversation1, $conversation2]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);
        // Should only transition once since both conversations have the same workspace
        $workspaceFacade->expects($this->once())
            ->method('transitionToAvailableForConversation')
            ->with('workspace-1');

        // Act
        $facade = new ChatBasedContentEditorFacade($entityManager, $workspaceFacade);
        $result = $facade->releaseStaleConversations(5);

        // Assert
        self::assertCount(1, $result);
        self::assertSame(['workspace-1'], $result);
    }

    public function testGetLatestConversationIdReturnsIdWhenConversationExists(): void
    {
        // Arrange
        $conversation = $this->createConversation(
            'conv-123',
            'workspace-1',
            'user-1',
            ConversationStatus::FINISHED,
            null
        );

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOneOrNullResult'])
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn($conversation);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);

        // Act
        $facade = new ChatBasedContentEditorFacade($entityManager, $workspaceFacade);
        $result = $facade->getLatestConversationId('workspace-1');

        // Assert
        self::assertSame('conv-123', $result);
    }

    public function testGetLatestConversationIdReturnsNullWhenNoConversationExists(): void
    {
        // Arrange
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOneOrNullResult'])
            ->getMock();
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        $workspaceFacade = $this->createMock(WorkspaceMgmtFacadeInterface::class);

        // Act
        $facade = new ChatBasedContentEditorFacade($entityManager, $workspaceFacade);
        $result = $facade->getLatestConversationId('workspace-1');

        // Assert
        self::assertNull($result);
    }

    /**
     * Helper to create a Conversation with reflection to set properties.
     */
    private function createConversation(
        string             $id,
        string             $workspaceId,
        string             $userId,
        ConversationStatus $status,
        ?DateTimeImmutable $lastActivityAt
    ): Conversation {
        $conversation = new Conversation($workspaceId, $userId, '/path/to/workspace');
        $conversation->setStatus($status);

        $reflection = new ReflectionClass($conversation);

        // Set the ID
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($conversation, $id);

        // Set lastActivityAt
        if ($lastActivityAt !== null) {
            $lastActivityProp = $reflection->getProperty('lastActivityAt');
            $lastActivityProp->setValue($conversation, $lastActivityAt);
        }

        return $conversation;
    }
}
