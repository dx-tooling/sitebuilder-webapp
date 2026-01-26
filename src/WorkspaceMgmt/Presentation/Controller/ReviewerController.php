<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Presentation\Controller;

use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Domain\Service\WorkspaceService;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for reviewer actions.
 * Uses internal WorkspaceService (same vertical).
 */
#[IsGranted('ROLE_REVIEWER')]
final class ReviewerController extends AbstractController
{
    public function __construct(
        private readonly WorkspaceService                      $workspaceService,
        private readonly ProjectMgmtFacadeInterface            $projectMgmtFacade,
        private readonly ChatBasedContentEditorFacadeInterface $chatBasedContentEditorFacade,
        private readonly TranslatorInterface                   $translator,
    ) {
    }

    #[Route(
        path: '/review',
        name: 'workspace_mgmt.presentation.review_list',
        methods: [Request::METHOD_GET]
    )]
    public function list(): Response
    {
        $workspacesInReview = $this->workspaceService->findByStatus(WorkspaceStatus::IN_REVIEW);

        $workspacesWithProject = [];
        foreach ($workspacesInReview as $workspace) {
            $projectInfo    = $this->projectMgmtFacade->getProjectInfo($workspace->getProjectId());
            $workspaceId    = $workspace->getId();
            $conversationId = $workspaceId !== null
                ? $this->chatBasedContentEditorFacade->getLatestConversationId($workspaceId)
                : null;

            $workspacesWithProject[] = [
                'workspace'      => $workspace,
                'project'        => $projectInfo,
                'conversationId' => $conversationId,
            ];
        }

        return $this->render('@workspace_mgmt.presentation/reviewer_dashboard.twig', [
            'workspacesWithProject' => $workspacesWithProject,
        ]);
    }

    #[Route(
        path: '/review/{workspaceId}/merge',
        name: 'workspace_mgmt.presentation.review_merge',
        methods: [Request::METHOD_POST],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function merge(string $workspaceId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('review_merge', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
        }

        $workspace = $this->workspaceService->findById($workspaceId);

        if ($workspace === null) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        if ($workspace->getStatus() !== WorkspaceStatus::IN_REVIEW) {
            $this->addFlash('error', $this->translator->trans('flash.error.workspace_not_in_review'));

            return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
        }

        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::MERGED);
        $this->addFlash('success', $this->translator->trans('flash.success.workspace_marked_merged'));

        return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
    }

    #[Route(
        path: '/review/{workspaceId}/unlock',
        name: 'workspace_mgmt.presentation.review_unlock',
        methods: [Request::METHOD_POST],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function unlock(string $workspaceId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('review_unlock', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
        }

        $workspace = $this->workspaceService->findById($workspaceId);

        if ($workspace === null) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        if ($workspace->getStatus() !== WorkspaceStatus::IN_REVIEW) {
            $this->addFlash('error', $this->translator->trans('flash.error.workspace_not_in_review'));

            return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
        }

        $this->workspaceService->transitionTo($workspace, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);
        $this->addFlash('success', $this->translator->trans('flash.success.workspace_unlocked'));

        return $this->redirectToRoute('workspace_mgmt.presentation.review_list');
    }
}
