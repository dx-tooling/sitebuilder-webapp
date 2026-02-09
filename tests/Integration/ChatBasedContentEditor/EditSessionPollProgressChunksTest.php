<?php

declare(strict_types=1);

namespace App\Tests\Integration\ChatBasedContentEditor;

use App\Account\Domain\Entity\AccountCore;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Enum\EditSessionStatus;
use App\ProjectMgmt\Domain\Entity\Project;
use App\Tests\Support\SecurityUserLoginTrait;
use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Throwable;

/**
 * Integration tests that progress chunks are persisted and returned by the poll endpoint.
 * Ensures the "chatty" progress stream is stored and exposed to the frontend.
 */
final class EditSessionPollProgressChunksTest extends WebTestCase
{
    use SecurityUserLoginTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager       = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher       = $container->get(UserPasswordHasherInterface::class);
        $this->passwordHasher = $passwordHasher;

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

    public function testPollReturnsProgressChunksWithMessageInPayload(): void
    {
        $user           = new AccountCore('poll-progress@example.com', '');
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user           = new AccountCore('poll-progress@example.com', $hashedPassword);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $project = new Project(
            'org-1',
            'Poll Progress Project',
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
        $conversation = new Conversation($workspaceId, $userId, '/workspace/path');
        $conversation->setStatus(ConversationStatus::ONGOING);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        $session = new EditSession($conversation, 'Build 10 landing pages');
        $session->setStatus(EditSessionStatus::Completed);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        EditSessionChunk::createProgressChunk($session, 'Reading package.json');
        EditSessionChunk::createProgressChunk($session, 'Editing dist/about.html');
        EditSessionChunk::createTextChunk($session, 'Done!');
        EditSessionChunk::createDoneChunk($session, true);
        $this->entityManager->flush();

        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $this->loginAsUser($this->client, $user);
        $this->client->request('GET', '/en/chat-based-content-editor/poll/' . $sessionId . '?after=0');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);
        self::assertArrayHasKey('chunks', $data);
        $chunks = $data['chunks'];
        self::assertIsArray($chunks);

        $progressChunks = array_values(array_filter(
            $chunks,
            static function (mixed $c): bool {
                return is_array($c) && ($c['chunkType'] ?? '') === 'progress';
            }
        ));
        self::assertCount(2, $progressChunks, 'Poll must return the two progress chunks');

        $messages = [];
        foreach ($progressChunks as $c) {
            $payloadJson = array_key_exists('payload', $c) && is_string($c['payload']) ? $c['payload'] : '{}';
            $payload     = json_decode($payloadJson, true);
            self::assertIsArray($payload);
            if (array_key_exists('message', $payload) && is_string($payload['message'])) {
                $messages[] = $payload['message'];
            }
        }
        self::assertContains('Reading package.json', $messages);
        self::assertContains('Editing dist/about.html', $messages);
    }
}
