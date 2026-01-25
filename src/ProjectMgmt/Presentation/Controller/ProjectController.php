<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\LlmContentEditor\Facade\LlmContentEditorFacadeInterface;
use App\ProjectMgmt\Domain\Service\ProjectService;
use App\ProjectMgmt\Facade\Dto\ExistingLlmApiKeyDto;
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

            $projectsWithStatus[] = [
                'project'          => $projectInfo,
                'workspace'        => $workspace,
                'inConversationBy' => $inConversationBy,
            ];
        }

        return $this->render('@project_mgmt.presentation/project_list.twig', [
            'projectsWithStatus' => $projectsWithStatus,
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
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $name             = $request->request->getString('name');
        $gitUrl           = $request->request->getString('git_url');
        $githubToken      = $request->request->getString('github_token');
        $llmModelProvider = LlmModelProvider::tryFrom($request->request->getString('llm_model_provider'));
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (optional - uses template defaults if empty)
        $agentBackgroundInstructions = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions       = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions     = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', 'All fields are required.');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', 'Please select an LLM model provider.');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', 'Invalid Docker image format. Expected format: name:tag');

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        $this->projectService->create(
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            ProjectType::DEFAULT,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions
        );
        $this->addFlash('success', 'Project created successfully.');

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

        if ($project === null) {
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

        if ($project === null) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('project_update', $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        $name             = $request->request->getString('name');
        $gitUrl           = $request->request->getString('git_url');
        $githubToken      = $request->request->getString('github_token');
        $llmModelProvider = LlmModelProvider::tryFrom($request->request->getString('llm_model_provider'));
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (null means keep existing values)
        $agentBackgroundInstructions = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions       = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions     = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', 'All fields are required.');

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', 'Please select an LLM model provider.');

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', 'Invalid Docker image format. Expected format: name:tag');

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
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions
        );
        $this->addFlash('success', 'Project updated successfully.');

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

        if ($project === null) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('delete_project_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        // Check if project has an active workspace
        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($id);
        if ($workspace !== null) {
            // Finish any ongoing conversations first
            $this->chatBasedContentEditorFacade->finishAllOngoingConversationsForWorkspace($workspace->id);
            // Delete the workspace
            $this->workspaceMgmtFacade->deleteWorkspace($workspace->id);
        }

        $projectName = $project->getName();
        $this->projectService->delete($project);
        $this->addFlash('success', sprintf('Project "%s" deleted successfully.', $projectName));

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

        if ($project === null) {
            throw $this->createNotFoundException('Project not found.');
        }

        if (!$this->isCsrfTokenValid('reset_workspace_' . $id, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        $workspace = $this->workspaceMgmtFacade->getWorkspaceForProject($id);

        if ($workspace === null) {
            $this->addFlash('warning', 'No workspace exists for this project.');

            return $this->redirectToRoute('project_mgmt.presentation.list');
        }

        try {
            // Finish all ongoing conversations
            $finishedCount = $this->chatBasedContentEditorFacade->finishAllOngoingConversationsForWorkspace(
                $workspace->id
            );

            // Reset workspace to AVAILABLE_FOR_SETUP
            $this->workspaceMgmtFacade->resetWorkspaceForSetup($workspace->id);

            $message = 'Workspace reset successfully.';
            if ($finishedCount > 0) {
                $message .= sprintf(' %d conversation(s) were finished.', $finishedCount);
            }
            $this->addFlash('success', $message);
        } catch (Throwable $e) {
            $this->addFlash('error', 'Failed to reset workspace: ' . $e->getMessage());
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
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $providerValue = $request->request->getString('provider');
        $apiKey        = $request->request->getString('api_key');

        if ($providerValue === '' || $apiKey === '') {
            return new JsonResponse(['success' => false, 'error' => 'Provider and API key are required.'], 400);
        }

        $provider = LlmModelProvider::tryFrom($providerValue);
        if ($provider === null) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid provider.'], 400);
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
            return new JsonResponse(['error' => 'Invalid project type.'], 400);
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
