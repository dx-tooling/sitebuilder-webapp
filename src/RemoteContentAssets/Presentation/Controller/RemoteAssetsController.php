<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Presentation\Controller;

use App\ChatBasedContentEditor\Facade\ChatBasedContentEditorFacadeInterface;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function sprintf;

/**
 * Controller for remote content assets browsing.
 * Provides API endpoints for listing remote assets from configured manifest URLs.
 */
#[IsGranted('ROLE_USER')]
final class RemoteAssetsController extends AbstractController
{
    /**
     * Maximum file size for uploads (10MB).
     */
    private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Allowed MIME types for uploads.
     *
     * @var list<string>
     */
    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/avif',
    ];

    public function __construct(
        private readonly ProjectMgmtFacadeInterface            $projectMgmtFacade,
        private readonly RemoteContentAssetsFacadeInterface    $remoteContentAssetsFacade,
        private readonly ChatBasedContentEditorFacadeInterface $chatBasedContentEditorFacade,
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

    /**
     * Upload a new asset to the project's S3 bucket.
     */
    #[Route(
        path: '/api/projects/{projectId}/remote-assets/upload',
        name: 'remote_content_assets.presentation.upload',
        methods: [Request::METHOD_POST],
        requirements: ['projectId' => '[a-f0-9-]{36}']
    )]
    public function upload(string $projectId, Request $request): JsonResponse
    {
        // Validate CSRF token
        if (!$this->isCsrfTokenValid('remote_asset_upload', $request->request->getString('_csrf_token'))) {
            return $this->json(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        // Get project info
        try {
            $project = $this->projectMgmtFacade->getProjectInfo($projectId);
        } catch (Throwable) {
            return $this->json(['error' => 'Project not found'], Response::HTTP_NOT_FOUND);
        }

        // Verify S3 is configured
        if (!$project->hasS3UploadConfigured()) {
            return $this->json(['error' => 'S3 upload is not configured for this project'], Response::HTTP_BAD_REQUEST);
        }

        // Get workspace ID for notification
        $workspaceId = $request->request->getString('workspace_id');
        if ($workspaceId === '') {
            return $this->json(['error' => 'Workspace ID is required'], Response::HTTP_BAD_REQUEST);
        }

        // Get uploaded file
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        // Validate file
        if (!$file->isValid()) {
            return $this->json(['error' => 'File upload failed: ' . $file->getErrorMessage()], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'File too large (max 10MB)'], Response::HTTP_BAD_REQUEST);
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'File type not allowed. Allowed: JPEG, PNG, GIF, WebP, SVG, AVIF'], Response::HTTP_BAD_REQUEST);
        }

        // Upload to S3
        try {
            $filename = $file->getClientOriginalName();
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                return $this->json(['error' => 'Failed to read file'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Safe to assert non-null since hasS3UploadConfigured() passed
            assert($project->s3BucketName !== null);
            assert($project->s3Region !== null);
            assert($project->s3AccessKeyId !== null);
            assert($project->s3SecretAccessKey !== null);

            $url = $this->remoteContentAssetsFacade->uploadAsset(
                $project->s3BucketName,
                $project->s3Region,
                $project->s3AccessKeyId,
                $project->s3SecretAccessKey,
                $project->s3IamRoleArn,
                $project->s3KeyPrefix,
                $filename,
                $contents,
                $mimeType
            );

            // Add system notification to conversation
            $this->chatBasedContentEditorFacade->addSystemNotification(
                $workspaceId,
                sprintf(
                    '[System Notification] A new remote asset "%s" was uploaded to S3. ' .
                    'Call list_remote_content_asset_urls to get the updated asset list ' .
                    '(note: the asset will appear once the external manifest is refreshed).',
                    $filename
                )
            );

            return $this->json([
                'success' => true,
                'url'     => $url,
            ]);
        } catch (Throwable $e) {
            return $this->json(['error' => 'Upload failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
