<?php

declare(strict_types=1);

namespace App\Tests\Integration\ChatBasedContentEditor;

use App\Account\Domain\Entity\AccountCore;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\ConversationMessage;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationMessageRole;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionChunkType;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use App\ChatBasedContentEditor\Infrastructure\Handler\RunEditSessionHandler;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\ProjectMgmt\Domain\Entity\Project;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_string;

final class RunEditSessionHandlerSimulationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RunEditSessionHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var RunEditSessionHandler $handler */
        $handler       = $container->get(RunEditSessionHandler::class);
        $this->handler = $handler;

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testSimulationCompletesAndPersistsExpectedChunkTypes(): void
    {
        $sessionId = $this->createSessionFixture('update the hero title');

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);
        self::assertSame(EditSessionStatus::Completed, $session->getStatus());

        $chunkTypes = array_values(array_map(
            static fn (EditSessionChunk $chunk): string => $chunk->getChunkType()->value,
            $session->getChunks()->toArray()
        ));

        self::assertContains(EditSessionChunkType::Event->value, $chunkTypes);
        self::assertContains(EditSessionChunkType::Progress->value, $chunkTypes);
        self::assertContains(EditSessionChunkType::Text->value, $chunkTypes);
        self::assertContains(EditSessionChunkType::Done->value, $chunkTypes);

        $doneChunk = $this->findLastDoneChunk($session);
        self::assertNotNull($doneChunk);
        $donePayload = json_decode($doneChunk->getPayloadJson(), true);
        self::assertIsArray($donePayload);
        self::assertTrue(($donePayload['success'] ?? null) === true);

        $messages = $this->entityManager
            ->getRepository(ConversationMessage::class)
            ->findBy(['conversation' => $session->getConversation()], ['sequence' => 'ASC']);

        self::assertNotEmpty($messages);
        self::assertTrue($this->hasMessageRole($messages, ConversationMessageRole::Assistant));
        self::assertTrue($this->hasMessageRole($messages, ConversationMessageRole::TurnActivitySummary));
    }

    public function testSimulationPersistsToolCallingAndToolCalledEvents(): void
    {
        $sessionId = $this->createSessionFixture('refactor card component [simulate_tool]');

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);

        $eventKinds = [];
        foreach ($session->getChunks() as $chunk) {
            if ($chunk->getChunkType() !== EditSessionChunkType::Event) {
                continue;
            }

            $payload = json_decode($chunk->getPayloadJson(), true);
            if (is_array($payload) && array_key_exists('kind', $payload) && is_string($payload['kind'])) {
                $eventKinds[] = $payload['kind'];
            }
        }

        self::assertContains('tool_calling', $eventKinds);
        self::assertContains('tool_called', $eventKinds);
    }

    public function testSimulationErrorMarksSessionAsFailedAndPersistsFailedDoneChunk(): void
    {
        $sessionId = $this->createSessionFixture('break it [simulate_error]');

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);
        self::assertSame(EditSessionStatus::Failed, $session->getStatus());

        $doneChunk = $this->findLastDoneChunk($session);
        self::assertNotNull($doneChunk);
        $donePayload = json_decode($doneChunk->getPayloadJson(), true);
        self::assertIsArray($donePayload);
        self::assertFalse(($donePayload['success'] ?? null) === true);
        self::assertSame('Simulated provider failure', $donePayload['errorMessage'] ?? null);
    }

    /**
     * When the LLM facade throws CancelledException during stream execution —
     * the path taken when the isCancelled callback fires mid-turn — the handler
     * must catch it and persist a Cancelled status with a failed Done chunk,
     * distinct from the error path (which produces a Failed status).
     */
    public function testCancellingDuringExecutionPersistsCancelledStatusAndFailedDoneChunk(): void
    {
        $sessionId = $this->createSessionFixture('simulate mid-execution stop [simulate_cancel_always]');

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);
        self::assertSame(EditSessionStatus::Cancelled, $session->getStatus());

        $doneChunk = $this->findLastDoneChunk($session);
        self::assertNotNull($doneChunk);

        $payload = json_decode($doneChunk->getPayloadJson(), true);
        self::assertIsArray($payload);
        self::assertFalse(($payload['success'] ?? null) === true);
        self::assertSame('Cancelled by user.', $payload['errorMessage'] ?? null);
    }

    public function testCancellingSessionDuringExecutionPersistsCancelledDoneChunk(): void
    {
        // Uses [simulate_cancel_always] marker: the simulated facade throws CancelledException
        // mid-stream after yielding initial chunks, verifying the handler's catch block and the
        // full CancelledException propagation path from facade through the foreach generator loop.
        $sessionId = $this->createSessionFixture('update hero title [simulate_cancel_always]');

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);
        self::assertSame(EditSessionStatus::Cancelled, $session->getStatus());

        $doneChunk = $this->findLastDoneChunk($session);
        self::assertNotNull($doneChunk);
        $payload = json_decode($doneChunk->getPayloadJson(), true);
        self::assertIsArray($payload);
        self::assertFalse(($payload['success'] ?? null) === true);
        self::assertSame('Cancelled by user.', $payload['errorMessage'] ?? null);
    }

    public function testCancellingSessionBeforeExecutionPersistsCancelledDoneChunk(): void
    {
        $sessionId = $this->createSessionFixture('normal flow');

        $session = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($session);
        $session->setStatus(EditSessionStatus::Cancelling);
        $this->entityManager->flush();

        ($this->handler)(new RunEditSessionMessage($sessionId, 'en'));

        $this->entityManager->clear();
        $reloadedSession = $this->entityManager->find(EditSession::class, $sessionId);
        self::assertNotNull($reloadedSession);
        self::assertSame(EditSessionStatus::Cancelled, $reloadedSession->getStatus());

        $doneChunk = $this->findLastDoneChunk($reloadedSession);
        self::assertNotNull($doneChunk);
        $payload = json_decode($doneChunk->getPayloadJson(), true);
        self::assertIsArray($payload);
        self::assertFalse(($payload['success'] ?? null) === true);
        self::assertSame('Cancelled before execution started.', $payload['errorMessage'] ?? null);
    }

    private function createSessionFixture(string $instruction): string
    {
        $user = new AccountCore('run-handler-sim@example.com', 'hashed-password');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $userId = $user->getId();
        self::assertNotNull($userId);

        $project = new Project(
            'org-test-1',
            'Run Handler Simulation Project',
            'https://github.com/org/repo.git',
            'token',
            LlmModelProvider::OpenAI,
            'simulated-key'
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
        self::assertNotNull($workspaceId);

        $conversation = new Conversation($workspaceId, $userId, '/workspace/run-handler-simulation');
        $conversation->setStatus(ConversationStatus::ONGOING);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        $session = new EditSession($conversation, $instruction);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        return $sessionId;
    }

    /**
     * @param array<int, ConversationMessage> $messages
     */
    private function hasMessageRole(array $messages, ConversationMessageRole $role): bool
    {
        $roles = array_values(array_map(
            static fn (ConversationMessage $message): string => $message->getRole()->value,
            $messages
        ));

        return in_array($role->value, $roles, true);
    }

    private function findLastDoneChunk(EditSession $session): ?EditSessionChunk
    {
        $doneChunks = array_values(array_filter(
            $session->getChunks()->toArray(),
            static fn (EditSessionChunk $chunk): bool => $chunk->getChunkType() === EditSessionChunkType::Done
        ));

        if ($doneChunks === []) {
            return null;
        }

        return $doneChunks[count($doneChunks) - 1];
    }

    private function cleanup(): void
    {
        $connection = $this->entityManager->getConnection();

        try {
            $connection->executeStatement('DELETE FROM edit_session_chunks');
            $connection->executeStatement('DELETE FROM edit_sessions');
            $connection->executeStatement('DELETE FROM conversation_messages');
            $connection->executeStatement('DELETE FROM conversations');
            $connection->executeStatement('DELETE FROM workspaces');
            $connection->executeStatement('DELETE FROM projects');
            $connection->executeStatement('DELETE FROM account_cores');
        } catch (Throwable) {
            // Tables may not exist yet during initial setup.
        }
    }
}
