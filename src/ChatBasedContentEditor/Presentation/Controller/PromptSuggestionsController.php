<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Domain\Entity\Conversation;
use App\ChatBasedContentEditor\Domain\Enum\ConversationStatus;
use App\ChatBasedContentEditor\Presentation\Service\PromptSuggestionsService;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OutOfRangeException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function is_array;
use function is_string;
use function trim;

/**
 * JSON API controller for CRUD operations on prompt suggestions.
 */
#[IsGranted('ROLE_USER')]
final class PromptSuggestionsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface       $entityManager,
        private readonly AccountFacadeInterface       $accountFacade,
        private readonly WorkspaceMgmtFacadeInterface $workspaceMgmtFacade,
        private readonly PromptSuggestionsService     $promptSuggestionsService,
    ) {
    }

    #[Route(
        path: '/conversation/{conversationId}/prompt-suggestions',
        name: 'chat_based_content_editor.presentation.prompt_suggestions.create',
        methods: [Request::METHOD_POST],
        requirements: ['conversationId' => '[a-f0-9-]{36}']
    )]
    public function create(
        string        $conversationId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        $workspace = $this->resolveEditableWorkspace($conversationId, $request, $user);

        $text = $this->getRequestText($request);
        if ($text === null) {
            return $this->json(['error' => 'Missing or empty "text" field.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $suggestions = $this->promptSuggestionsService->addSuggestion($workspace->workspacePath, $text);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['suggestions' => $suggestions]);
    }

    #[Route(
        path: '/conversation/{conversationId}/prompt-suggestions/{index}',
        name: 'chat_based_content_editor.presentation.prompt_suggestions.update',
        methods: [Request::METHOD_PUT],
        requirements: ['conversationId' => '[a-f0-9-]{36}', 'index' => '\d+']
    )]
    public function update(
        string        $conversationId,
        int           $index,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        $workspace = $this->resolveEditableWorkspace($conversationId, $request, $user);

        $text = $this->getRequestText($request);
        if ($text === null) {
            return $this->json(['error' => 'Missing or empty "text" field.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $suggestions = $this->promptSuggestionsService->updateSuggestion($workspace->workspacePath, $index, $text);
        } catch (InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (OutOfRangeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['suggestions' => $suggestions]);
    }

    #[Route(
        path: '/conversation/{conversationId}/prompt-suggestions/{index}',
        name: 'chat_based_content_editor.presentation.prompt_suggestions.delete',
        methods: [Request::METHOD_DELETE],
        requirements: ['conversationId' => '[a-f0-9-]{36}', 'index' => '\d+']
    )]
    public function delete(
        string        $conversationId,
        int           $index,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        $workspace = $this->resolveEditableWorkspace($conversationId, $request, $user);

        try {
            $suggestions = $this->promptSuggestionsService->deleteSuggestion($workspace->workspacePath, $index);
        } catch (OutOfRangeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['suggestions' => $suggestions]);
    }

    /**
     * Resolve conversation → workspace and enforce authorization + CSRF.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    private function resolveEditableWorkspace(
        string        $conversationId,
        Request       $request,
        UserInterface $user,
    ): \App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto {
        if (!$this->isCsrfTokenValid('prompt-suggestions', $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $conversation = $this->entityManager->find(Conversation::class, $conversationId);
        if ($conversation === null) {
            throw $this->createNotFoundException('Conversation not found.');
        }

        $accountInfo = $this->accountFacade->getAccountInfoByEmail($user->getUserIdentifier());
        if ($accountInfo === null) {
            throw new RuntimeException('Account not found for authenticated user');
        }

        if ($conversation->getUserId() !== $accountInfo->id) {
            throw $this->createAccessDeniedException('Only the conversation owner can manage prompt suggestions.');
        }

        if ($conversation->getStatus() !== ConversationStatus::ONGOING) {
            throw $this->createAccessDeniedException('Cannot modify prompt suggestions for a finished conversation.');
        }

        $workspace = $this->workspaceMgmtFacade->getWorkspaceById($conversation->getWorkspaceId());
        if ($workspace === null) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return $workspace;
    }

    private function getRequestText(Request $request): ?string
    {
        $content = $request->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (
            !is_array($data)
            || !array_key_exists('text', $data)
            || !is_string($data['text'])
            || trim($data['text']) === ''
        ) {
            return null;
        }

        return $data['text'];
    }
}
