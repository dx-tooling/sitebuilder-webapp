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
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
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
        private readonly RemoteContentAssetsFacadeInterface    $remoteContentAssetsFacade,
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
        $user = $this->getUser();

        // Release any stale conversations (where users left without finishing)
        // This ensures workspaces become available again after 5 minutes of inactivity
        $this->chatBasedContentEditorFacade->releaseStaleConversations();

        // Get the user's active organization
        $organizationId = $this->getActiveOrganizationId();
        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_organization'));

            return $this->redirectToRoute('account.presentation.dashboard');
        }

        $projects = $this->projectService->findAllForOrganization($organizationId);

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
            if ($workspace !== null) {
                $conversationId = $this->chatBasedContentEditorFacade->getLatestConversationId($workspace->id);
            }
            /*
            if ($workspace !== null && $workspace->status === WorkspaceStatus::IN_REVIEW) {
                $conversationId = $this->chatBasedContentEditorFacade->getLatestConversationId($workspace->id);
            }
            */

            $projectsWithStatus[] = [
                'project'          => $projectInfo,
                'workspace'        => $workspace,
                'inConversationBy' => $inConversationBy,
                'conversationId'   => $conversationId,
            ];
        }

        // Get soft-deleted projects for the "Deleted projects" section
        $deletedProjects = $this->projectService->findAllDeletedForOrganization($organizationId);

        return $this->render('@project_mgmt.presentation/project_list.twig', [
            'user'               => $user,
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
        // Get the user's active organization
        $organizationId = $this->getActiveOrganizationId();
        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_organization'));

            return $this->redirectToRoute('account.presentation.dashboard');
        }

        // Get default agent config template for new projects
        $defaultTemplate = $this->projectMgmtFacade->getAgentConfigTemplate(ProjectType::DEFAULT);

        return $this->render('@project_mgmt.presentation/project_form.twig', [
            'project'             => null,
            'llmProviders'        => LlmModelProvider::cases(),
            'existingLlmKeys'     => $this->projectMgmtFacade->getExistingLlmApiKeys($organizationId),
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
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (optional - uses template defaults if empty)
        $agentBackgroundInstructions     = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions           = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions         = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));
        $remoteContentAssetsManifestUrls = $this->parseRemoteContentAssetsManifestUrls($request);

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', $this->translator->trans('flash.error.all_fields_required'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_llm_provider'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_docker_image'));

            return $this->redirectToRoute('project_mgmt.presentation.new');
        }

        // Get the user's active organization
        $organizationId = $this->getActiveOrganizationId();
        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_organization'));

            return $this->redirectToRoute('account.presentation.dashboard');
        }

        // S3 upload configuration (all optional)
        $s3BucketName      = $this->nullIfEmpty($request->request->getString('s3_bucket_name'));
        $s3Region          = $this->nullIfEmpty($request->request->getString('s3_region'));
        $s3AccessKeyId     = $this->nullIfEmpty($request->request->getString('s3_access_key_id'));
        $s3SecretAccessKey = $this->nullIfEmpty($request->request->getString('s3_secret_access_key'));
        $s3IamRoleArn      = $this->nullIfEmpty($request->request->getString('s3_iam_role_arn'));
        $s3KeyPrefix       = $this->nullIfEmpty($request->request->getString('s3_key_prefix'));

        $this->projectService->create(
            $organizationId,
            $name,
            $gitUrl,
            $githubToken,
            $llmModelProvider,
            $llmApiKey,
            ProjectType::DEFAULT,
            $agentImage,
            $agentBackgroundInstructions,
            $agentStepInstructions,
            $agentOutputInstructions,
            $remoteContentAssetsManifestUrls,
            $s3BucketName,
            $s3Region,
            $s3AccessKeyId,
            $s3SecretAccessKey,
            $s3IamRoleArn,
            $s3KeyPrefix
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

        // Get the user's active organization (for filtering existing LLM keys)
        $organizationId = $this->getActiveOrganizationId();
        if ($organizationId === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.no_organization'));

            return $this->redirectToRoute('account.presentation.dashboard');
        }

        // Filter out the current project's key from the reuse list
        // Only show keys from the user's organization (security boundary)
        $currentKey      = $project->getLlmApiKey();
        $existingLlmKeys = array_values(array_filter(
            $this->projectMgmtFacade->getExistingLlmApiKeys($organizationId),
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
        $llmApiKey        = $request->request->getString('llm_api_key');
        $agentImage       = $this->resolveAgentImage($request);

        // Agent configuration (null means keep existing values)
        $agentBackgroundInstructions     = $this->nullIfEmpty($request->request->getString('agent_background_instructions'));
        $agentStepInstructions           = $this->nullIfEmpty($request->request->getString('agent_step_instructions'));
        $agentOutputInstructions         = $this->nullIfEmpty($request->request->getString('agent_output_instructions'));
        $remoteContentAssetsManifestUrls = $this->parseRemoteContentAssetsManifestUrls($request);

        if ($name === '' || $gitUrl === '' || $githubToken === '' || $llmApiKey === '') {
            $this->addFlash('error', $this->translator->trans('flash.error.all_fields_required'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($llmModelProvider === null) {
            $this->addFlash('error', $this->translator->trans('flash.error.select_llm_provider'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        if ($agentImage === '' || !$this->isValidDockerImageName($agentImage)) {
            $this->addFlash('error', $this->translator->trans('flash.error.invalid_docker_image'));

            return $this->redirectToRoute('project_mgmt.presentation.edit', ['id' => $id]);
        }

        // S3 upload configuration (all optional)
        $s3BucketName      = $this->nullIfEmpty($request->request->getString('s3_bucket_name'));
        $s3Region          = $this->nullIfEmpty($request->request->getString('s3_region'));
        $s3AccessKeyId     = $this->nullIfEmpty($request->request->getString('s3_access_key_id'));
        $s3SecretAccessKey = $this->nullIfEmpty($request->request->getString('s3_secret_access_key'));
        $s3IamRoleArn      = $this->nullIfEmpty($request->request->getString('s3_iam_role_arn'));
        $s3KeyPrefix       = $this->nullIfEmpty($request->request->getString('s3_key_prefix'));

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
            $agentOutputInstructions,
            $remoteContentAssetsManifestUrls,
            $s3BucketName,
            $s3Region,
            $s3AccessKeyId,
            $s3SecretAccessKey,
            $s3IamRoleArn,
            $s3KeyPrefix
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

    #[Route(
        path: '/projects/verify-manifest-url',
        name: 'project_mgmt.presentation.verify_manifest_url',
        methods: [Request::METHOD_POST]
    )]
    public function verifyManifestUrl(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('verify_manifest_url', $request->request->getString('_csrf_token'))) {
            return new JsonResponse(['valid' => false, 'error' => $this->translator->trans('api.error.invalid_csrf')], 403);
        }

        $url = trim($request->request->getString('url'));
        if ($url === '') {
            return new JsonResponse(['valid' => false, 'error' => $this->translator->trans('api.error.url_required')], 400);
        }

        $valid = $this->remoteContentAssetsFacade->isValidManifestUrl($url);

        return new JsonResponse(['valid' => $valid]);
    }

    #[Route(
        path: '/projects/verify-s3-credentials',
        name: 'project_mgmt.presentation.verify_s3_credentials',
        methods: [Request::METHOD_POST]
    )]
    public function verifyS3Credentials(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('verify_s3_credentials', $request->request->getString('_csrf_token'))) {
            return new JsonResponse(['valid' => false, 'error' => $this->translator->trans('api.error.invalid_csrf')], 403);
        }

        $bucketName      = trim($request->request->getString('bucket_name'));
        $region          = trim($request->request->getString('region'));
        $accessKeyId     = trim($request->request->getString('access_key_id'));
        $secretAccessKey = trim($request->request->getString('secret_access_key'));
        $iamRoleArn      = trim($request->request->getString('iam_role_arn'));

        if ($bucketName === '' || $region === '' || $accessKeyId === '' || $secretAccessKey === '') {
            return new JsonResponse(['valid' => false, 'error' => $this->translator->trans('api.error.s3_required_fields')], 400);
        }

        $valid = $this->remoteContentAssetsFacade->verifyS3Credentials(
            $bucketName,
            $region,
            $accessKeyId,
            $secretAccessKey,
            $iamRoleArn === '' ? null : $iamRoleArn
        );

        return new JsonResponse(['valid' => $valid]);
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

    /**
     * Parse remote content assets manifest URLs from request (textarea, one URL per line).
     * Returns only valid http/https URLs; invalid lines are skipped.
     *
     * @return list<string>
     */
    private function parseRemoteContentAssetsManifestUrls(Request $request): array
    {
        $raw   = $request->request->getString('remote_content_assets_manifest_urls');
        $lines = preg_split('/\r\n|\r|\n/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $urls  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($this->isValidManifestUrlSyntax($line)) {
                $urls[] = $line;
            }
        }

        return $urls;
    }

    /**
     * Check that the string is a valid http or https URL (syntax only).
     */
    private function isValidManifestUrlSyntax(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !array_key_exists('scheme', $parsed) || !array_key_exists('host', $parsed)) {
            return false;
        }

        return $parsed['scheme'] === 'http' || $parsed['scheme'] === 'https';
    }

    /**
     * Get the currently active organization ID for the logged-in user.
     */
    private function getActiveOrganizationId(): ?string
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $accountInfo = $this->accountFacade->getAccountInfoByEmail($user->getUserIdentifier());
        if ($accountInfo === null) {
            return null;
        }

        return $this->accountFacade->getCurrentlyActiveOrganizationIdForAccountCore($accountInfo->id);
    }
}
