<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Controller;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use App\ChatBasedContentEditor\Presentation\Service\ConversationContextUsageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;

final class ChatBasedContentEditorController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'chat_based_content_editor.workspace_root')]
        private readonly string                          $workspaceRoot,
        private readonly EntityManagerInterface          $entityManager,
        private readonly MessageBusInterface             $messageBus,
        private readonly ConversationContextUsageService $contextUsageService,
    ) {
    }

    #[Route(
        path: '/chat-based-content-editor',
        name: 'chat_based_content_editor.presentation.index',
        methods: [Request::METHOD_GET]
    )]
    public function index(): Response
    {
        /** @var Conversation|null $latestConversation */
        $latestConversation = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($latestConversation !== null) {
            return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
                'conversationId' => $latestConversation->getId(),
            ]);
        }

        $workspacePath = $this->workspaceRoot !== '' ? $this->workspaceRoot : '/tmp';
        $conversation  = new Conversation($workspacePath);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
            'conversationId' => $conversation->getId(),
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/new',
        name: 'chat_based_content_editor.presentation.new',
        methods: [Request::METHOD_GET]
    )]
    public function newConversation(): Response
    {
        $workspacePath = $this->workspaceRoot !== '' ? $this->workspaceRoot : '/tmp';
        $conversation  = new Conversation($workspacePath);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
            'conversationId' => $conversation->getId(),
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/{conversationId}',
        name: 'chat_based_content_editor.presentation.show',
        methods: [Request::METHOD_GET],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function show(string $conversationId): Response
    {
        $conversation = $this->entityManager->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            return $this->redirectToRoute('chat_based_content_editor.presentation.index');
        }

        /** @var list<Conversation> $conversations */
        $conversations = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Conversation::class, 'c')
            ->where('c.id != :currentId')
            ->setParameter('currentId', $conversationId)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $pastConversations = [];
        foreach ($conversations as $conv) {
            $firstSession = $conv->getEditSessions()->first();
            $preview      = $firstSession instanceof EditSession
                ? mb_substr($firstSession->getInstruction(), 0, 100)
                : '';

            $pastConversations[] = [
                'id'           => $conv->getId(),
                'createdAt'    => $conv->getCreatedAt()->format('Y-m-d H:i'),
                'preview'      => $preview,
                'messageCount' => $conv->getEditSessions()->count(),
            ];
        }

        $turns = [];
        foreach ($conversation->getEditSessions() as $session) {
            $assistantResponse = '';
            foreach ($session->getChunks() as $chunk) {
                if ($chunk->getChunkType()->value === 'text') {
                    $payload = json_decode($chunk->getPayloadJson(), true);
                    if (is_array($payload) && array_key_exists('content', $payload) && is_string($payload['content'])) {
                        $assistantResponse .= $payload['content'];
                    }
                }
            }

            $turns[] = [
                'instruction' => $session->getInstruction(),
                'response'    => $assistantResponse,
                'status'      => $session->getStatus()->value,
            ];
        }

        $contextUsage = $this->contextUsageService->getContextUsage($conversation);

        return $this->render('@chat_based_content_editor.presentation/chat_based_content_editor.twig', [
            'conversation'      => $conversation,
            'turns'             => $turns,
            'pastConversations' => $pastConversations,
            'runUrl'            => $this->generateUrl('chat_based_content_editor.presentation.run'),
            'pollUrlTemplate'   => $this->generateUrl('chat_based_content_editor.presentation.poll', ['sessionId' => '__SESSION_ID__']),
            'contextUsage'      => [
                'usedTokens' => $contextUsage->usedTokens,
                'maxTokens'  => $contextUsage->maxTokens,
                'modelName'  => $contextUsage->modelName,
            ],
            'contextUsageUrl' => $this->generateUrl('chat_based_content_editor.presentation.context_usage', ['conversationId' => $conversation->getId()]),
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/{conversationId}/context-usage',
        name: 'chat_based_content_editor.presentation.context_usage',
        methods: [Request::METHOD_GET],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function contextUsage(string $conversationId): Response
    {
        $conversation = $this->entityManager->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            return $this->json(['error' => 'Conversation not found.'], Response::HTTP_NOT_FOUND);
        }

        $dto = $this->contextUsageService->getContextUsage($conversation);

        return $this->json([
            'usedTokens' => $dto->usedTokens,
            'maxTokens'  => $dto->maxTokens,
            'modelName'  => $dto->modelName,
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/run',
        name: 'chat_based_content_editor.presentation.run',
        methods: [Request::METHOD_POST]
    )]
    public function run(Request $request): Response
    {
        $instruction    = $request->request->getString('instruction');
        $conversationId = $request->request->getString('conversation_id');

        if ($instruction === '') {
            return $this->json(['error' => 'Instruction is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('chat_based_content_editor_run', $request->request->getString('_csrf_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $conversation = $this->entityManager->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            return $this->json(['error' => 'Conversation not found.'], Response::HTTP_NOT_FOUND);
        }

        $session = new EditSession($conversation, $instruction);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $sessionId = $session->getId();
        if ($sessionId === null) {
            return $this->json(['error' => 'Failed to create session.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->messageBus->dispatch(new RunEditSessionMessage($sessionId));

        return $this->json([
            'sessionId' => $sessionId,
        ]);
    }

    #[Route(
        path: '/chat-based-content-editor/poll/{sessionId}',
        name: 'chat_based_content_editor.presentation.poll',
        methods: [Request::METHOD_GET]
    )]
    public function poll(string $sessionId, Request $request): Response
    {
        $session = $this->entityManager->find(EditSession::class, $sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $after = $request->query->getInt('after', 0);
        $limit = 100;

        /** @var list<EditSessionChunk> $chunks */
        $chunks = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(EditSessionChunk::class, 'c')
            ->where('c.session = :session')
            ->andWhere('c.id > :after')
            ->setParameter('session', $session)
            ->setParameter('after', $after)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $lastId    = $after;
        $chunkData = [];

        foreach ($chunks as $chunk) {
            $chunkId = $chunk->getId();
            if ($chunkId !== null && $chunkId > $lastId) {
                $lastId = $chunkId;
            }

            $chunkData[] = [
                'id'        => $chunkId,
                'chunkType' => $chunk->getChunkType()->value,
                'payload'   => $chunk->getPayloadJson(),
            ];
        }

        $conversation = $session->getConversation();
        $contextUsage = $this->contextUsageService->getContextUsage($conversation);

        return $this->json([
            'chunks'       => $chunkData,
            'lastId'       => $lastId,
            'status'       => $session->getStatus()->value,
            'contextUsage' => [
                'usedTokens' => $contextUsage->usedTokens,
                'maxTokens'  => $contextUsage->maxTokens,
                'modelName'  => $contextUsage->modelName,
            ],
        ]);
    }
}
