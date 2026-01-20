<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Controller;

use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use const DIRECTORY_SEPARATOR;

final class ChatBasedContentEditorController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'chat_based_content_editor.workspace_root')]
        private readonly string                 $workspaceRoot,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface    $messageBus,
    ) {
    }

    #[Route(
        path: '/chat-based-content-editor',
        name: 'chat_based_content_editor.presentation.index',
        methods: [Request::METHOD_GET]
    )]
    public function index(): Response
    {
        return $this->render('@chat_based_content_editor.presentation/chat_based_content_editor.twig', [
            'runUrl'               => $this->generateUrl('chat_based_content_editor.presentation.run'),
            'pollUrlTemplate'      => $this->generateUrl('chat_based_content_editor.presentation.poll', ['sessionId' => '__SESSION_ID__']),
            'defaultWorkspacePath' => $this->workspaceRoot,
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
        $workspacePath  = $request->request->getString('workspace_path');
        $conversationId = $request->request->getString('conversation_id');

        if ($instruction === '') {
            return $this->json(['error' => 'Instruction is required.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('chat_based_content_editor_run', $request->request->getString('_csrf_token'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        // Load existing conversation or validate workspace path for new one
        $conversation = null;
        if ($conversationId !== '') {
            $conversation = $this->entityManager->find(Conversation::class, $conversationId);
            if ($conversation === null) {
                return $this->json(['error' => 'Conversation not found.'], Response::HTTP_NOT_FOUND);
            }
            // Use the conversation's workspace path
            $real = $conversation->getWorkspacePath();
        } else {
            // New conversation - validate workspace path
            $resolved = $workspacePath !== '' ? $workspacePath : $this->workspaceRoot;
            if ($resolved === '') {
                return $this->json(
                    ['error' => 'Workspace path is required. Set CHAT_EDITOR_WORKSPACE_ROOT or provide it in the form.'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $real = realpath($resolved);
            if ($real === false || !is_dir($real)) {
                return $this->json(
                    ['error' => 'Workspace path does not exist or is not a directory.'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            if ($this->workspaceRoot !== '') {
                $allowed = realpath($this->workspaceRoot);
                if ($allowed === false) {
                    return $this->json(
                        ['error' => 'Configured workspace root does not exist.'],
                        Response::HTTP_BAD_REQUEST
                    );
                }
                $prefix = $allowed . DIRECTORY_SEPARATOR;
                if ($real !== $allowed && !str_starts_with($real . DIRECTORY_SEPARATOR, $prefix)) {
                    return $this->json(
                        ['error' => 'Workspace path is not under the allowed root.'],
                        Response::HTTP_BAD_REQUEST
                    );
                }
            }

            // Create new conversation
            $conversation = new Conversation($real);
            $this->entityManager->persist($conversation);
        }

        // Create session within conversation
        $session = EditSession::createWithConversation($conversation, $instruction);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $sessionId = $session->getId();
        if ($sessionId === null) {
            return $this->json(['error' => 'Failed to create session.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->messageBus->dispatch(new RunEditSessionMessage($sessionId));

        return $this->json([
            'sessionId'      => $sessionId,
            'conversationId' => $conversation->getId(),
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

        return $this->json([
            'chunks' => $chunkData,
            'lastId' => $lastId,
            'status' => $session->getStatus()->value,
        ]);
    }
}
