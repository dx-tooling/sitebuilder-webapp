<?php

declare(strict_types=1);

namespace App\Tests\Integration\ChatBasedContentEditor;

use App\Account\Domain\Entity\AccountCore;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\ConversationMessage;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationMessageRole;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use App\ChatBasedContentEditor\Presentation\Service\ConversationContextUsageService;
use App\ProjectMgmt\Domain\Entity\Project;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Integration tests for ConversationContextUsageService.
 * Asserts that "usedTokens" reflects current context (can shrink when turn ends)
 * and "totalCost" is cumulative (only grows).
 */
final class ConversationContextUsageServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ConversationContextUsageService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var ConversationContextUsageService $service */
        $service       = $container->get(ConversationContextUsageService::class);
        $this->service = $service;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->entityManager->getConnection();
        try {
            $conn->executeStatement('DELETE FROM edit_session_chunks');
            $conn->executeStatement('DELETE FROM edit_sessions');
            $conn->executeStatement('DELETE FROM conversation_messages');
            $conn->executeStatement('DELETE FROM conversations');
            $conn->executeStatement('DELETE FROM workspaces');
            $conn->executeStatement('DELETE FROM projects');
            $conn->executeStatement('DELETE FROM account_cores');
        } catch (Throwable) {
            // Tables may not exist
        }
    }

    public function testUsedTokensWithoutActiveSessionExcludesEventChunks(): void
    {
        $conversation = $this->createConversationWithMessagesAndSessions();

        $dtoWithout     = $this->service->getContextUsage($conversation, null);
        $dtoWithRunning = $this->service->getContextUsage($conversation, $this->runningSessionId);

        // Without active session, current context = messages + system prompt only (no event chunks).
        // With running session, it includes that session's event bytes, so usedTokens should be higher.
        self::assertGreaterThan(0, $dtoWithout->usedTokens);
        self::assertLessThan(
            $dtoWithRunning->usedTokens,
            $dtoWithout->usedTokens,
            'Without active session, usedTokens should be less than with running session (no tool traffic in current context)'
        );
        self::assertGreaterThan(0, $dtoWithout->totalCost, 'Cost should be cumulative');
    }

    public function testUsedTokensWithRunningSessionIncludesThatSessionsEventBytes(): void
    {
        $conversation     = $this->createConversationWithMessagesAndSessions();
        $runningSessionId = $this->runningSessionId;

        $dtoWithout = $this->service->getContextUsage($conversation, null);
        $dtoWith    = $this->service->getContextUsage($conversation, $runningSessionId);

        // With active running session, usedTokens should be higher (includes that session's tool context)
        self::assertGreaterThan(
            $dtoWithout->usedTokens,
            $dtoWith->usedTokens,
            'With active session, usedTokens should include that session\'s event chunk bytes'
        );
        // Cost is cumulative and same in both cases
        self::assertSame($dtoWithout->totalCost, $dtoWith->totalCost);
    }

    public function testUsedTokensWithCompletedSessionExcludesThatSessionsEventBytes(): void
    {
        $conversation       = $this->createConversationWithMessagesAndSessions();
        $completedSessionId = $this->completedSessionId;

        $dtoWithout       = $this->service->getContextUsage($conversation, null);
        $dtoWithCompleted = $this->service->getContextUsage($conversation, $completedSessionId);

        // Completed session should not add to current context (same as null)
        self::assertSame(
            $dtoWithout->usedTokens,
            $dtoWithCompleted->usedTokens,
            'With completed session id, usedTokens should equal no session (current context does not include past turn tool traffic)'
        );
    }

    public function testTotalCostIsCumulativeAndSameRegardlessOfActiveSession(): void
    {
        $conversation = $this->createConversationWithMessagesAndSessions();

        $dtoNull      = $this->service->getContextUsage($conversation, null);
        $dtoRunning   = $this->service->getContextUsage($conversation, $this->runningSessionId);
        $dtoCompleted = $this->service->getContextUsage($conversation, $this->completedSessionId);

        self::assertSame($dtoNull->totalCost, $dtoRunning->totalCost);
        self::assertSame($dtoNull->totalCost, $dtoCompleted->totalCost);
    }

    private ?string $runningSessionId   = null;
    private ?string $completedSessionId = null;

    private function createConversationWithMessagesAndSessions(): Conversation
    {
        $user = new AccountCore('budget-test@example.com', 'hash');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $project = new Project(
            'org-1',
            'Budget Test Project',
            'https://github.com/org/repo.git',
            'token',
            \App\LlmContentEditor\Facade\Enum\LlmModelProvider::OpenAI,
            'sk-key'
        );
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $projectId = $project->getId();
        self::assertNotNull($projectId);
        $workspace = new Workspace($projectId);
        $workspace->setStatus(WorkspaceStatus::IN_CONVERSATION);
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        $workspaceId = $workspace->getId();
        $userId      = $user->getId();
        self::assertNotNull($workspaceId);
        self::assertNotNull($userId);
        $conversation = new Conversation(
            $workspaceId,
            $userId,
            '/workspace/path'
        );
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        new ConversationMessage($conversation, ConversationMessageRole::User, json_encode(['content' => 'Hello'], JSON_THROW_ON_ERROR));
        new ConversationMessage($conversation, ConversationMessageRole::Assistant, json_encode(['content' => str_repeat('x', 200)], JSON_THROW_ON_ERROR));
        $this->entityManager->flush();

        $runningSession = new EditSession($conversation, 'Run tool');
        $runningSession->setStatus(EditSessionStatus::Running);
        $this->entityManager->persist($runningSession);
        $this->entityManager->flush();
        $this->runningSessionId = $runningSession->getId();

        EditSessionChunk::createEventChunk($runningSession, '{"kind":"tool_calling"}', 5000);
        EditSessionChunk::createEventChunk($runningSession, '{"kind":"tool_called"}', 10000);
        $this->entityManager->flush();

        $completedSession = new EditSession($conversation, 'Done');
        $completedSession->setStatus(EditSessionStatus::Completed);
        $this->entityManager->persist($completedSession);
        $this->entityManager->flush();
        $this->completedSessionId = $completedSession->getId();

        EditSessionChunk::createEventChunk($completedSession, '{"kind":"tool_calling"}', 3000);
        EditSessionChunk::createEventChunk($completedSession, '{"kind":"tool_called"}', 7000);
        EditSessionChunk::createTextChunk($completedSession, 'Assistant reply');
        $this->entityManager->flush();

        return $conversation;
    }
}
