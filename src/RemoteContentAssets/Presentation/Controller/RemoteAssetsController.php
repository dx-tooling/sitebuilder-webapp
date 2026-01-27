<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Presentation\Controller;

use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

/**
 * Controller for remote content assets browsing.
 * Provides API endpoints for listing remote assets from configured manifest URLs.
 */
#[IsGranted('ROLE_USER')]
final class RemoteAssetsController extends AbstractController
{
    public function __construct(
        private readonly ProjectMgmtFacadeInterface         $projectMgmtFacade,
        private readonly RemoteContentAssetsFacadeInterface $remoteContentAssetsFacade,
    ) {
    }

    /**
     * List all remote assets for a project by fetching and merging manifest URLs.
     */
    #[Route(
        path: '/api/projects/{projectId}/remote-assets',
        name: 'remote_content_assets.presentation.list',
        methods: [Request::METHOD_GET],
        requirements: ['projectId' => '[a-f0-9-]{36}']
    )]
    public function list(string $projectId): JsonResponse
    {
        try {
            $project = $this->projectMgmtFacade->getProjectInfo($projectId);
        } catch (Throwable) {
            return $this->json(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        $urls = $this->remoteContentAssetsFacade->fetchAndMergeAssetUrls(
            $project->remoteContentAssetsManifestUrls
        );

        return $this->json(['urls' => $urls]);
    }
}
