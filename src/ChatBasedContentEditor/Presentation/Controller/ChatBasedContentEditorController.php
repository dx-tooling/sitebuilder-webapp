<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\AccountInfoDto;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Entity\EditSession;
use App\ChatBasedContentEditor\Domain\Entity\EditSessionChunk;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Domain\Service\ConversationService;
use App\ChatBasedContentEditor\Infrastructure\Adapter\DistFileScannerInterface;
use App\ChatBasedContentEditor\Infrastructure\Message\RunEditSessionMessage;
use App\ChatBasedContentEditor\Presentation\Service\ConversationContextUsageService;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_string;
use function json_decode;

/**
 * Controller for chat-based content editing.
 * Uses internal ConversationService and cross-vertical facades.
 */
#[IsGranted('ROLE_USER')]
final class ChatBasedContentEditorController extends AbstractController
{
    public function __construct(
        private readonly ConversationService             $conversationService,
        private readonly WorkspaceMgmtFacadeInterface    $workspaceMgmtFacade,
        private readonly ProjectMgmtFacadeInterface      $projectMgmtFacade,
        private readonly AccountFacadeInterface          $accountFacade,
        private readonly EntityManagerInterface          $entityManager,
        private readonly MessageBusInterface             $messageBus,
        private readonly DistFileScannerInterface        $distFileScanner,
        private readonly ConversationContextUsageService $contextUsageService,
    ) {
    }

    /**
     * Resolve security user to domain AccountInfoDto via facade.
     * Uses getUserIdentifier() which returns the email for our AccountCore entity.
     */
    private function getAccountInfo(UserInterface $user): AccountInfoDto
    {
        $accountInfo = $this->accountFacade->getAccountInfoByEmail($user->getUserIdentifier());

        if ($accountInfo === null) {
            throw new RuntimeException('Account not found for authenticated user');
        }

        return $accountInfo;
    }

    #[Route(
        path: '/projects/{projectId}/conversation',
        name: 'chat_based_content_editor.presentation.start',
        methods: [Request::METHOD_GET],
        requirements: ['projectId' => '[a-f0-9-]{36}']
    )]
    public function startConversation(
        string        $projectId,
        #[CurrentUser] UserInterface $user
    ): Response {
        $accountInfo = $this->getAccountInfo($user);
        $project     = $this->projectMgmtFacade->getProjectInfo($projectId);

        // Check workspace status before starting
        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($projectId);

        if ($workspace !== null) {
            // Handle special statuses
            if ($workspace->status === WorkspaceStatus::IN_REVIEW) {
                $this->addFlash('warning', 'This workspace is currently in review. No conversations can be started.');

                return $this->redirectToRoute('project_mgmt.presentation.list');
            }

            if ($workspace->status === WorkspaceStatus::PROBLEM) {
                return $this->render('@chat_based_content_editor.presentation/workspace_problem.twig', [
                    'workspace' => $workspace,
                    'project'   => $project,
                ]);
            }

            // If workspace is setting up, show the setup waiting page
            if ($workspace->status === WorkspaceStatus::IN_SETUP) {
                return $this->render('@chat_based_content_editor.presentation/workspace_setup.twig', [
                    'workspace'   => $workspace,
                    'project'     => $project,
                    'pollUrl'     => $this->generateUrl('chat_based_content_editor.presentation.poll_workspace_status', ['workspaceId' => $workspace->id]),
                    'redirectUrl' => $this->generateUrl('chat_based_content_editor.presentation.start', ['projectId' => $projectId]),
                ]);
            }

            if ($workspace->status === WorkspaceStatus::IN_CONVERSATION) {
                // Check if there's an ongoing conversation for another user
                $existingConversation = $this->conversationService->findOngoingConversation(
                    $workspace->id,
                    $accountInfo->id
                );

                if ($existingConversation === null) {
                    // Find who is working on it
                    $otherConversation = $this->conversationService->findAnyOngoingConversationForWorkspace($workspace->id);
                    $otherUserEmail    = 'another user';

                    if ($otherConversation !== null) {
                        $otherAccount = $this->accountFacade->getAccountInfoById($otherConversation->getUserId());
                        if ($otherAccount !== null) {
                            $otherUserEmail = $otherAccount->email;
                        }
                    }

                    $this->addFlash('warning', sprintf('%s is currently working on this workspace.', $otherUserEmail));

                    return $this->redirectToRoute('project_mgmt.presentation.list');
                }
            }
        }

        try {
            // Dispatch async setup if needed - this will start setup in background
            $workspace = $this->workspaceMgmtFacade->dispatchSetupIfNeeded($projectId);

            // If setup was dispatched (workspace is now IN_SETUP), show waiting page
            if ($workspace->status === WorkspaceStatus::IN_SETUP) {
                return $this->render('@chat_based_content_editor.presentation/workspace_setup.twig', [
                    'workspace'   => $workspace,
                    'project'     => $project,
                    'pollUrl'     => $this->generateUrl('chat_based_content_editor.presentation.poll_workspace_status', ['workspaceId' => $workspace->id]),
                    'redirectUrl' => $this->generateUrl('chat_based_content_editor.presentation.start', ['projectId' => $projectId]),
                ]);
            }

            // Workspace is ready - start or resume conversation
            $conversationInfo = $this->conversationService->startOrResumeConversation($projectId, $accountInfo->id);

            return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
                'conversationId' => $conversationInfo->id,
            ]);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Failed to start conversation: ' . $e->getMessage());

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }
    }

    #[Route(
        path: '/workspace/{workspaceId}/status',
        name: 'chat_based_content_editor.presentation.poll_workspace_status',
        methods: [Request::METHOD_GET],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function pollWorkspaceStatus(string $workspaceId): Response
    {
        $workspace = $this->workspaceMgmtFacade->getWorkspaceById($workspaceId);

        if ($workspace === null) {
            return $this->json(['error' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'status' => $workspace->status->name,
            'ready'  => $workspace->status === WorkspaceStatus::AVAILABLE_FOR_CONVERSATION,
            'error'  => $workspace->status === WorkspaceStatus::PROBLEM,
        ]);
    }

    #[Route(
        path: '/conversation/{conversationId}',
        name: 'chat_based_content_editor.presentation.show',
        methods: [Request::METHOD_GET],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function show(
        string        $conversationId,
        #[CurrentUser] UserInterface $user
    ): Response {
        $conversation = $this->entityManager->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            throw $this->createNotFoundException('Conversation not found.');
        }

        $accountInfo = $this->getAccountInfo($user);

        // Authorization: Only the conversation owner can view it
        if ($conversation->getUserId() !== $accountInfo->id) {
            throw $this->createAccessDeniedException('You do not have access to this conversation.');
        }

        // Authorization: Only ONGOING conversations can be viewed
        // Finished conversations should not be accessible - users should start new conversations instead
        if ($conversation->getStatus() !== ConversationStatus::ONGOING) {
            $this->addFlash('info', 'This conversation has been finished. Please start a new conversation to continue working.');

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        // Get workspace info for status display
        $workspace   = $this->workspaceMgmtFacade->getWorkspaceById($conversation->getWorkspaceId());
        $projectInfo = null;

        if ($workspace !== null) {
            $projectInfo = $this->projectMgmtFacade->getProjectInfo($workspace->projectId);
        }

        // Build turns from edit sessions
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

        // Since we already verified ownership and status above, canEdit is always true here
        $canEdit = true;

        $contextUsage = $this->contextUsageService->getContextUsage($conversation);

        return $this->render('@chat_based_content_editor.presentation/chat_based_content_editor.twig', [
            'conversation'    => $conversation,
            'workspace'       => $workspace,
            'project'         => $projectInfo,
            'turns'           => $turns,
            'canEdit'         => $canEdit,
            'runUrl'          => $this->generateUrl('chat_based_content_editor.presentation.run'),
            'pollUrlTemplate' => $this->generateUrl('chat_based_content_editor.presentation.poll', ['sessionId' => '__SESSION_ID__']),
            'contextUsage'    => [
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
        path: '/conversation/{conversationId}/finish',
        name: 'chat_based_content_editor.presentation.finish',
        methods: [Request::METHOD_POST],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function finish(
        string        $conversationId,
        Request       $request,
        #[CurrentUser] UserInterface $user
    ): Response {
        if (!$this->isCsrfTokenValid('conversation_finish', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
                'conversationId' => $conversationId,
            ]);
        }

        $accountInfo = $this->getAccountInfo($user);

        try {
            $this->conversationService->finishConversation($conversationId, $accountInfo->id);
            $this->addFlash('success', 'Conversation finished. Workspace is now available for new conversations.');
        } catch (Throwable $e) {
            $this->addFlash('error', 'Failed to finish conversation: ' . $e->getMessage());
        }

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/conversation/{conversationId}/send-to-review',
        name: 'chat_based_content_editor.presentation.send_to_review',
        methods: [Request::METHOD_POST],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function sendToReview(
        string        $conversationId,
        Request       $request,
        #[CurrentUser] UserInterface $user
    ): Response {
        if (!$this->isCsrfTokenValid('conversation_review', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('chat_based_content_editor.presentation.show', [
                'conversationId' => $conversationId,
            ]);
        }

        $accountInfo = $this->getAccountInfo($user);

        try {
            $prUrl = $this->conversationService->sendToReview($conversationId, $accountInfo->id);
            if ($prUrl === '') {
                $this->addFlash('success', 'Conversation finished. No changes to review.');
            } else {
                $this->addFlash('success', 'Conversation sent to review. Pull request: ' . $prUrl);
            }
        } catch (Throwable $e) {
            $this->addFlash('error', 'Failed to send to review: ' . $e->getMessage());
        }

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/workspace/{workspaceId}/reset',
        name: 'chat_based_content_editor.presentation.reset_workspace',
        methods: [Request::METHOD_POST],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function resetWorkspace(string $workspaceId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('workspace_reset', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        try {
            $this->workspaceMgmtFacade->resetProblemWorkspace($workspaceId);
            $this->addFlash('success', 'Workspace reset. You can now start a new conversation.');
        } catch (Throwable $e) {
            $this->addFlash('error', 'Failed to reset workspace: ' . $e->getMessage());
        }

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/chat-based-content-editor/run',
        name: 'chat_based_content_editor.presentation.run',
        methods: [Request::METHOD_POST]
    )]
    public function run(
        Request       $request,
        #[CurrentUser] UserInterface $user
    ): Response {
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

        // Verify user owns this conversation
        $accountInfo = $this->getAccountInfo($user);
        if ($conversation->getUserId() !== $accountInfo->id) {
            return $this->json(['error' => 'Not authorized to run edits in this conversation.'], Response::HTTP_FORBIDDEN);
        }

        // Verify conversation is still ongoing
        if ($conversation->getStatus() !== ConversationStatus::ONGOING) {
            return $this->json(['error' => 'Conversation is no longer active.'], Response::HTTP_BAD_REQUEST);
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

    #[Route(
        path: '/workspace/{workspaceId}/dist-files',
        name: 'chat_based_content_editor.presentation.dist_files',
        methods: [Request::METHOD_GET],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function distFiles(string $workspaceId): Response
    {
        $workspace = $this->workspaceMgmtFacade->getWorkspaceById($workspaceId);

        if ($workspace === null) {
            return $this->json(['error' => 'Workspace not found.'], Response::HTTP_NOT_FOUND);
        }

        $distFiles = $this->distFileScanner->scanDistHtmlFiles($workspace->id, $workspace->workspacePath);

        $files = [];
        foreach ($distFiles as $distFile) {
            $files[] = [
                'path' => $distFile->path,
                'url'  => $distFile->url,
            ];
        }

        return $this->json(['files' => $files]);
    }
}
