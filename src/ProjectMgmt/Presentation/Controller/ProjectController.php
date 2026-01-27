<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\ProjectMgmt\Domain\Service\ProjectService;
use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
use App\ProjectMgmt\Facade\Enum\ContentEditorBackend;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Controller for project management.
 * Uses internal ProjectService and WorkspaceMgmtFacade for cross-vertical access.
 */
#[IsGranted('ROLE_USER')]
final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectService                        $projectService,
        private readonly ProjectMgmtFacadeInterface            $projectMgmtFacade,
        private readonly WorkspaceMgmtFacadeInterface          $workspaceMgmtFacade,
        private readonly ChatBasedContentEditorFacadeInterface $chatBasedContentEditorFacade,
        private readonly AccountFacadeInterface                $accountFacade,
        private readonly LlmContentEditorFacadeInterface       $llmContentEditorFacade,
        private readonly TranslatorInterface                   $translator,
    ) {
    }

    #[Route(
        path: '/projects',
        name: 'project_mgmt.presentation.list',
        methods: [Request::METHOD_GET]
    )]
    public function list(): Response
    {
        // Release any stale conversations (where users left without finishing)
        // This ensures workspaces become available again after 5 minutes of inactivity
        $this->chatBasedContentEditorFacade->releaseStaleConversations();

        $projects = $this->projectService->findAll();

        $projectsWithStatus = [];
        foreach ($projects as $project) {
            $projectId = $project->getId();
            if ($projectId === null) {
                continue;
            }

            // Get project info DTO which includes GitHub URL
            $projectInfo      = $this->projectMgmtFacade->getProjectInfo($projectId);
            $workspace        = $this->workspaceMgmtFacade->getWorkspaceForProject($projectId);
            $inConversationBy = null;

            // If workspace is in conversation, get the user's email
            if ($workspace !== null && $workspace->status === WorkspaceStatus::IN_CONVERSATION) {
                $userId = $this->chatBasedContentEditorFacade->getOngoingConversationUserId($workspace->id);
                if ($userId !== null) {
                    $account          = $this->accountFacade->getAccountInfoById($userId);
                    $inConversationBy = $account?->email;
                }
            }

            // If workspace is in review, get the conversation ID for read-only view
            $conversationId = null;
            if ($workspace !== null && $workspace->status === WorkspaceStatus::IN_REVIEW) {
                $conversationId = $this->chatBasedContentEditorFacade->getLatestConversationId($workspace->id);
            }

            $projectsWithStatus[] = [
                'project'          => $projectInfo,
                'workspace'        => $workspace,
                'inConversationBy' => $inConversationBy,
                'conversationId'   => $conversationId,
            ];
        }

        // Get soft-deleted projects for the "Deleted projects" section
        $deletedProjects = $this->projectService->findAllDeleted();

        return $this->render('@project_mgmt.presentation/project_list.twig', [
            'projectsWithStatus' => $projectsWithStatus,
            'deletedProjects'    => $deletedProjects,
        ]);
    }

    #[Route(
        path: '/projects/new',
        name: 'project_mgmt.presentation.new',
        methods: [Request::METHOD_GET]
    )]
    public function new(): Response
    {
        // Get default agent config template for new projects
        $defaultTemplate = $this->projectMgmtFacade->getAgentConfigTemplate(ProjectType::DEFAULT);

        return $this->render('@project_mgmt.presentation/project_form.twig', [
            'project'             => null,
            'llmProviders'        => LlmModelProvider::cases(),
            'contentEditorBackends' => ContentEditorBackend::cases(),
            'existingLlmKeys'     => $this->projectMgmtFacade->getExistingLlmApiKeys(),
            'agentConfigTemplate' => $defaultTemplate,
        ]);
    }

    #[Route(
        path: '/projects',
        name: 'project_mgmt.presentation.create',
        methods: [Request::METHOD_POST]
    )]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project_create', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $name             = $request->request->getString('name');
        $gitUrl           = $request->request->getString('git_url');
        $githubToken      = $request->request->getString('github_token');
        $llmModelProvider = LlmModelProvider::tryFrom($request->request->getString('llm_model_provider'));
        $contentEditorBackend = ContentEditorBackend::tryFrom($request->request->getString('content_editor_backend'));
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (optional - uses template defaults if empty)
        $agentBackgroundInstructions = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions       = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions     = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', $this->translator->trans('flash.error.all_fields_required'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_llm_provider'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($contentEditorBackend === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_content_editor_backend'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_docker_image'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $this->projectService->create(
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            ProjectType::DEFAULT,
            $contentEditorBackend,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions
        );
        $this->addFlash('success', $this->translator->trans('flash.success.project_created'));

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/edit',
        name: 'project_mgmt.presentation.edit',
        methods: [Request::METHOD_GET],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function edit(string $id): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || $project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        // Filter out the current project's key from the reuse list
        $currentKey      = $project->getLlmApiKey();
        $existingLlmKeys = array_values(array_filter(
            $this->projectMgmtFacade->getExistingLlmApiKeys(),
            static fn (ExistingLlmApiKeyDto $key) => $key->apiKey !== $currentKey
        ));

        // Get agent config template (used as fallback in template, but project values take precedence)
        $agentConfigTemplate = $this->projectMgmtFacade->getAgentConfigTemplate($project->getProjectType());

        return $this->render('@project_mgmt.presentation/project_form.twig', [
            'project'             => $project,
            'llmProviders'        => LlmModelProvider::cases(),
            'contentEditorBackends' => ContentEditorBackend::cases(),
            'existingLlmKeys'     => $existingLlmKeys,
            'agentConfigTemplate' => $agentConfigTemplate,
        ]);
    }

    #[Route(
        path: '/projects/{id}',
        name: 'project_mgmt.presentation.update',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function update(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || $project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('project_update', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        $name             = $request->request->getString('name');
        $gitUrl           = $request->request->getString('git_url');
        $githubToken      = $request->request->getString('github_token');
        $llmModelProvider = LlmModelProvider::tryFrom($request->request->getString('llm_model_provider'));
        $contentEditorBackend = ContentEditorBackend::tryFrom($request->request->getString('content_editor_backend'));
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (null means keep existing values)
        $agentBackgroundInstructions = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions       = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions     = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', $this->translator->trans('flash.error.all_fields_required'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_llm_provider'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($contentEditorBackend === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_content_editor_backend'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_docker_image'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        $this->projectService->update(
            $project,
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            ProjectType::DEFAULT,
            $contentEditorBackend,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions
        );
        $this->addFlash('success', $this->translator->trans('flash.success.project_updated'));

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/delete',
        name: 'project_mgmt.presentation.delete',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function delete(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || $project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('delete_project_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        // Finish any ongoing conversations for this project's workspace
        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($id);
        if ($workspace !== null) {
            $this->chatBasedContentEditorFacade->finishAllOngoingConversationsForWorkspace($workspace->id);
        }

        $projectName = $project->getName();
        $this->projectService->delete($project);
        $this->addFlash('success', $this->translator->trans('flash.success.project_deleted', ['%name%' => $projectName]));

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/permanently-delete',
        name: 'project_mgmt.presentation.permanently_delete',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function permanentlyDelete(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || !$project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('permanently_delete_project_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        // Delete associated workspace if exists
        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($id);
        if ($workspace !== null) {
            $this->chatBasedContentEditorFacade->finishAllOngoingConversationsForWorkspace($workspace->id);
            $this->workspaceMgmtFacade->deleteWorkspace($workspace->id);
        }

        $projectName = $project->getName();
        $this->projectService->permanentlyDelete($project);
        $this->addFlash('success', $this->translator->trans('flash.success.project_permanently_deleted', ['%name%' => $projectName]));

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/restore',
        name: 'project_mgmt.presentation.restore',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function restore(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || !$project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('restore_project_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        $projectName = $project->getName();
        $this->projectService->restore($project);
        $this->addFlash('success', $this->translator->trans('flash.success.project_restored', ['%name%' => $projectName]));

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/{id}/reset-workspace',
        name: 'project_mgmt.presentation.reset_workspace',
        methods: [Request::METHOD_POST],
        requirements: ['id' => '[a-f0-9-]{36}']
    )]
    public function resetWorkspace(string $id, Request $request): Response
    {
        $project = $this->projectService->findById($id);

        if ($project === null || $project->isDeleted()) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('reset_workspace_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_csrf'));

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($id);

        if ($workspace === null) {
            $this->addFlash('warning', $this->translator->trans('flash.error.no_workspace'));

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        try {
            // Finish all ongoing conversations
            $finishedCount = $this->chatBasedContentEditorFacade->finishAllOngoingConversationsForWorkspace(
                $workspace->id
            );

            // Reset workspace to AVAILABLE_FOR_SETUP
            $this->workspaceMgmtFacade->resetWorkspaceForSetup($workspace->id);

            if ($finishedCount > 0) {
                $this->addFlash('success', $this->translator->trans('flash.success.workspace_reset_with_conversations', ['%count%' => $finishedCount]));
            } else {
                $this->addFlash('success', $this->translator->trans('flash.success.workspace_reset'));
            }
        } catch (Throwable $e) {
            $this->addFlash('error', $this->translator->trans('flash.error.workspace_reset_failed', ['%error%' => $e->getMessage()]));
        }

        return $this->redirectToRoute('project_mgmt.presentation.list');
    }

    #[Route(
        path: '/projects/verify-llm-key',
        name: 'project_mgmt.presentation.verify_llm_key',
        methods: [Request::METHOD_POST]
    )]
    public function verifyLlmKey(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('verify_llm_key', $request->request->getString('_csrf_token'))) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('api.error.invalid_csrf')], 403);
        }

        $providerValue = $request->request->getString('provider');
        $apiKey        = $request->request->getString('api_key');

        if ($providerValue === '' || $apiKey === '') {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('api.error.provider_and_key_required')], 400);
        }

        $provider = LlmModelProvider::tryFrom($providerValue);
        if ($provider === null) {
            return new JsonResponse(['success' => false, 'error' => $this->translator->trans('api.error.invalid_provider')], 400);
        }

        $isValid = $this->llmContentEditorFacade->verifyApiKey($provider, $apiKey);

        return new JsonResponse(['success' => $isValid]);
    }

    /**
     * Resolve the agent image from the request.
     * If "custom" is selected, use the custom_agent_image field.
     */
    private function resolveAgentImage(Request $request): string
    {
        $agentImage = $request->request->getString('agent_image');

        if ($agentImage === 'custom') {
            return $request->request->getString('custom_agent_image');
        }

        return $agentImage;
    }

    /**
     * Get agent configuration template for a project type.
     * Used by frontend to populate defaults when project type changes.
     */
    #[Route(
        path: '/projects/agent-config-template/{type}',
        name: 'project_mgmt.presentation.agent_config_template',
        methods: [Request::METHOD_GET]
    )]
    public function getAgentConfigTemplate(string $type): JsonResponse
    {
        $projectType = ProjectType::tryFrom($type);
        if ($projectType === null) {
            return new JsonResponse(['error' => $this->translator->trans('api.error.invalid_project_type')], 400);
        }

        $template = $this->projectMgmtFacade->getAgentConfigTemplate($projectType);

        return new JsonResponse([
            'backgroundInstructions' => $template->backgroundInstructions,
            'stepInstructions'       => $template->stepInstructions,
            'outputInstructions'     => $template->outputInstructions,
        ]);
    }

    /**
     * Validate Docker image name format.
     * Accepts formats like: name:tag, registry/name:tag, registry:port/name:tag.
     */
    private function isValidDockerImageName(string $imageName): bool
    {
        // Basic validation: must contain a colon for tag, alphanumeric with allowed chars
        // Allows: letters, numbers, dots, dashes, underscores, slashes, colons
        return preg_match('/^[\w.\-\/]+:[\w.\-]+$/', $imageName) === 1;
    }

    /**
     * Returns null if string is empty, otherwise returns the string.
     */
    private function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
