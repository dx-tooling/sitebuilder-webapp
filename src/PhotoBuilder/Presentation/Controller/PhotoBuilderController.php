<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Presentation\Controller;

use App\Account\Facade\AccountFacadeInterface;
use App\Account\Facade\Dto\AccountInfoDto;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\PhotoBuilder\Domain\Entity\PhotoImage;
use App\PhotoBuilder\Domain\Entity\PhotoSession;
use App\PhotoBuilder\Domain\Enum\PhotoImageStatus;
use App\PhotoBuilder\Domain\Enum\PhotoSessionStatus;
use App\PhotoBuilder\Domain\Service\PhotoBuilderService;
use App\PhotoBuilder\Infrastructure\Message\GenerateImageMessage;
use App\PhotoBuilder\Infrastructure\Message\GenerateImagePromptsMessage;
use App\PhotoBuilder\Infrastructure\Storage\GeneratedImageStorage;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use App\WorkspaceMgmt\Facade\Dto\WorkspaceInfoDto;
use App\WorkspaceMgmt\Facade\WorkspaceMgmtFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_map;
use function basename;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;

use const PHP_URL_PATH;

#[IsGranted('ROLE_USER')]
final class PhotoBuilderController extends AbstractController
{
    public function __construct(
        private readonly PhotoBuilderService                $photoBuilderService,
        private readonly WorkspaceMgmtFacadeInterface       $workspaceMgmtFacade,
        private readonly ProjectMgmtFacadeInterface         $projectMgmtFacade,
        private readonly AccountFacadeInterface             $accountFacade,
        private readonly RemoteContentAssetsFacadeInterface $remoteContentAssetsFacade,
        private readonly EntityManagerInterface             $entityManager,
        private readonly MessageBusInterface                $messageBus,
        private readonly GeneratedImageStorage              $imageStorage,
    ) {
    }

    private function getAccountInfo(UserInterface $user): AccountInfoDto
    {
        $accountInfo = $this->accountFacade->getAccountInfoByEmail($user->getUserIdentifier());

        if ($accountInfo === null) {
            throw new RuntimeException('Account not found for authenticated user');
        }

        return $accountInfo;
    }

    /**
     * @return array{WorkspaceInfoDto, ProjectInfoDto}
     */
    private function loadWorkspaceAndProject(string $workspaceId): array
    {
        $workspace = $this->workspaceMgmtFacade->getWorkspaceById($workspaceId);

        if ($workspace === null) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return [$workspace, $this->projectMgmtFacade->getProjectInfo($workspace->projectId)];
    }

    /**
     * Render the PhotoBuilder page.
     */
    #[Route(
        path: '/photo-builder/{workspaceId}',
        name: 'photo_builder.presentation.show',
        methods: [Request::METHOD_GET],
        requirements: ['workspaceId' => '[a-f0-9-]{36}']
    )]
    public function show(
        string        $workspaceId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): Response {
        $this->getAccountInfo($user);

        [$workspace, $project] = $this->loadWorkspaceAndProject($workspaceId);

        $pagePath       = $request->query->getString('page');
        $conversationId = $request->query->getString('conversationId');

        if ($pagePath === '' || $conversationId === '') {
            throw $this->createNotFoundException('Missing required query parameters: page, conversationId');
        }

        $hasRemoteAssets = $project->hasS3UploadConfigured()
            && count($project->remoteContentAssetsManifestUrls) > 0;

        $effectiveProvider = $project->getEffectivePhotoBuilderLlmModelProvider();

        return $this->render('@photo_builder.presentation/photo_builder.twig', [
            'workspace'                     => $workspace,
            'project'                       => $project,
            'pagePath'                      => $pagePath,
            'conversationId'                => $conversationId,
            'imageCount'                    => PhotoBuilderService::IMAGE_COUNT,
            'hasRemoteAssets'               => $hasRemoteAssets,
            'effectivePhotoBuilderProvider' => $effectiveProvider->displayName(),
            'imagePromptModel'              => $effectiveProvider->imagePromptGenerationModel()->value,
            'imageGenerationModel'          => $effectiveProvider->imageGenerationModel()->value,
            'supportsResolutionToggle'      => $effectiveProvider === LlmModelProvider::Google,
        ]);
    }

    /**
     * Create a photo session and start prompt generation.
     */
    #[Route(
        path: '/api/photo-builder/sessions',
        name: 'photo_builder.presentation.create_session',
        methods: [Request::METHOD_POST],
    )]
    public function createSession(
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $workspaceId    = is_string($data['workspaceId'] ?? null) ? $data['workspaceId'] : '';
        $conversationId = is_string($data['conversationId'] ?? null) ? $data['conversationId'] : '';
        $pagePath       = is_string($data['pagePath'] ?? null) ? $data['pagePath'] : '';
        $userPrompt     = is_string($data['userPrompt'] ?? null) ? $data['userPrompt'] : '';

        if ($workspaceId === '' || $conversationId === '' || $pagePath === '' || $userPrompt === '') {
            return $this->json(['error' => 'Missing required fields.'], Response::HTTP_BAD_REQUEST);
        }

        $session = $this->photoBuilderService->createSession(
            $workspaceId,
            $conversationId,
            $pagePath,
            $userPrompt,
        );
        $sessionId = $session->getId();

        if ($sessionId === null) {
            return $this->json(['error' => 'Failed to create session.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->messageBus->dispatch(new GenerateImagePromptsMessage(
            $sessionId,
            $request->getLocale(),
        ));

        return $this->json([
            'sessionId' => $sessionId,
            'status'    => $session->getStatus()->value,
        ]);
    }

    /**
     * Poll session status with all image data.
     */
    #[Route(
        path: '/api/photo-builder/sessions/{sessionId}',
        name: 'photo_builder.presentation.poll_session',
        methods: [Request::METHOD_GET],
        requirements: ['sessionId' => '[a-f0-9-]{36}']
    )]
    public function pollSession(
        string        $sessionId,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        $this->getAccountInfo($user);

        $session = $this->entityManager->find(PhotoSession::class, $sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $images = array_map(
            fn (PhotoImage $image): array => [
                'id'                => $image->getId(),
                'position'          => $image->getPosition(),
                'prompt'            => $image->getPrompt(),
                'suggestedFileName' => $image->getSuggestedFileName(),
                'status'            => $image->getStatus()->value,
                'imageUrl'          => $image->getStoragePath() !== null
                    ? $this->generateUrl('photo_builder.presentation.serve_image', [
                        'imageId' => $image->getId(),
                    ])
                    : null,
                'errorMessage'         => $image->getErrorMessage(),
                'uploadedToMediaStore' => $image->getUploadedToMediaStoreAt() !== null,
                'uploadedFileName'     => $image->getUploadedFileName(),
            ],
            $session->getImages()->toArray()
        );

        return $this->json([
            'status'     => $session->getStatus()->value,
            'userPrompt' => $session->getUserPrompt(),
            'images'     => $images,
        ]);
    }

    /**
     * Regenerate prompts with an updated user prompt.
     */
    #[Route(
        path: '/api/photo-builder/sessions/{sessionId}/regenerate-prompts',
        name: 'photo_builder.presentation.regenerate_prompts',
        methods: [Request::METHOD_POST],
        requirements: ['sessionId' => '[a-f0-9-]{36}']
    )]
    public function regeneratePrompts(
        string        $sessionId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $session = $this->entityManager->find(PhotoSession::class, $sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $data    = json_decode($request->getContent(), true);
        $keepIds = [];

        if (is_array($data)) {
            $userPrompt = is_string($data['userPrompt'] ?? null) ? $data['userPrompt'] : '';

            if ($userPrompt !== '') {
                $session->setUserPrompt($userPrompt);
            }

            $keepImageIds = $data['keepImageIds'] ?? null;
            if (is_array($keepImageIds)) {
                foreach ($keepImageIds as $id) {
                    if (is_string($id) && $id !== '') {
                        $keepIds[] = $id;
                    }
                }
            }
        }

        $sessionId = $session->getId();

        if ($sessionId === null) {
            return $this->json(['error' => 'Session has no ID.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $session->setStatus(PhotoSessionStatus::GeneratingPrompts);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateImagePromptsMessage(
            $sessionId,
            $request->getLocale(),
            $keepIds,
        ));

        return $this->json([
            'status' => $session->getStatus()->value,
        ]);
    }

    /**
     * Update prompt for a single image.
     */
    #[Route(
        path: '/api/photo-builder/images/{imageId}/update-prompt',
        name: 'photo_builder.presentation.update_prompt',
        methods: [Request::METHOD_POST],
        requirements: ['imageId' => '[a-f0-9-]{36}']
    )]
    public function updatePrompt(
        string        $imageId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $image = $this->entityManager->find(PhotoImage::class, $imageId);

        if ($image === null) {
            return $this->json(['error' => 'Image not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (is_array($data)) {
            $prompt = is_string($data['prompt'] ?? null) ? $data['prompt'] : '';

            if ($prompt !== '') {
                $image->setPrompt($prompt);
                $this->entityManager->flush();
            }
        }

        return $this->json(['status' => 'ok']);
    }

    /**
     * Regenerate a single image.
     */
    #[Route(
        path: '/api/photo-builder/images/{imageId}/regenerate',
        name: 'photo_builder.presentation.regenerate_image',
        methods: [Request::METHOD_POST],
        requirements: ['imageId' => '[a-f0-9-]{36}']
    )]
    public function regenerateImage(
        string        $imageId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $image = $this->entityManager->find(PhotoImage::class, $imageId);

        if ($image === null) {
            return $this->json(['error' => 'Image not found.'], Response::HTTP_NOT_FOUND);
        }

        $imageId = $image->getId();

        if ($imageId === null) {
            return $this->json(['error' => 'Image has no ID.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $data      = json_decode($request->getContent(), true);
        $imageSize = is_array($data) && is_string($data['imageSize'] ?? null) ? $data['imageSize'] : null;

        $image->setStatus(PhotoImageStatus::Pending);
        $image->setStoragePath(null);
        $image->setErrorMessage(null);
        $image->setUploadedToMediaStoreAt(null);
        $image->setUploadedFileName(null);

        $session = $image->getSession();
        $session->setStatus(PhotoSessionStatus::GeneratingImages);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateImageMessage($imageId, $imageSize));

        return $this->json(['status' => 'ok']);
    }

    /**
     * Regenerate all images in a session (e.g. after resolution change).
     */
    #[Route(
        path: '/api/photo-builder/sessions/{sessionId}/regenerate-all-images',
        name: 'photo_builder.presentation.regenerate_all_images',
        methods: [Request::METHOD_POST],
        requirements: ['sessionId' => '[a-f0-9-]{36}']
    )]
    public function regenerateAllImages(
        string        $sessionId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $session = $this->entityManager->find(PhotoSession::class, $sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found.'], Response::HTTP_NOT_FOUND);
        }

        $data      = json_decode($request->getContent(), true);
        $imageSize = is_array($data) && is_string($data['imageSize'] ?? null) ? $data['imageSize'] : null;

        $session->setStatus(PhotoSessionStatus::GeneratingImages);

        foreach ($session->getImages() as $image) {
            $imgId = $image->getId();

            if ($imgId === null || $image->getPrompt() === null || $image->getPrompt() === '') {
                continue;
            }

            $image->setStatus(PhotoImageStatus::Pending);
            $image->setStoragePath(null);
            $image->setErrorMessage(null);
            $image->setUploadedToMediaStoreAt(null);
            $image->setUploadedFileName(null);
        }

        $this->entityManager->flush();

        foreach ($session->getImages() as $image) {
            $imgId = $image->getId();

            if ($imgId === null || $image->getPrompt() === null || $image->getPrompt() === '') {
                continue;
            }

            $this->messageBus->dispatch(new GenerateImageMessage($imgId, $imageSize));
        }

        return $this->json(['status' => 'ok']);
    }

    /**
     * Serve a generated image file.
     */
    #[Route(
        path: '/api/photo-builder/images/{imageId}/file',
        name: 'photo_builder.presentation.serve_image',
        methods: [Request::METHOD_GET],
        requirements: ['imageId' => '[a-f0-9-]{36}']
    )]
    public function serveImage(
        string        $imageId,
        #[CurrentUser] UserInterface $user,
    ): Response {
        $this->getAccountInfo($user);

        $image = $this->entityManager->find(PhotoImage::class, $imageId);

        if ($image === null || $image->getStoragePath() === null) {
            throw $this->createNotFoundException('Image not found.');
        }

        $absolutePath = $this->imageStorage->getAbsolutePath($image->getStoragePath());

        if (!$this->imageStorage->exists($image->getStoragePath())) {
            throw $this->createNotFoundException('Image file not found on disk.');
        }

        return new BinaryFileResponse($absolutePath, 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * Upload a generated image to the media store (S3).
     */
    #[Route(
        path: '/api/photo-builder/images/{imageId}/upload-to-media-store',
        name: 'photo_builder.presentation.upload_to_media_store',
        methods: [Request::METHOD_POST],
        requirements: ['imageId' => '[a-f0-9-]{36}']
    )]
    public function uploadToMediaStore(
        string        $imageId,
        Request       $request,
        #[CurrentUser] UserInterface $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('photo_builder', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $this->getAccountInfo($user);

        $image = $this->entityManager->find(PhotoImage::class, $imageId);

        if ($image === null || $image->getStoragePath() === null) {
            return $this->json(['error' => 'Image not found or not yet generated.'], Response::HTTP_NOT_FOUND);
        }

        $session = $image->getSession();

        [$workspace, $project] = $this->loadWorkspaceAndProject($session->getWorkspaceId());

        if (
            !$project->hasS3UploadConfigured()
            || $project->s3BucketName      === null
            || $project->s3Region          === null
            || $project->s3AccessKeyId     === null
            || $project->s3SecretAccessKey === null
        ) {
            return $this->json(['error' => 'S3 upload not configured for this project.'], Response::HTTP_BAD_REQUEST);
        }

        $fileName = $image->getSuggestedFileName() ?? 'generated-image-' . $image->getPosition() . '.png';

        if ($image->getUploadedToMediaStoreAt() !== null) {
            return $this->json([
                'url'              => '',
                'fileName'         => $fileName,
                'uploadedFileName' => $image->getUploadedFileName(),
            ]);
        }

        $imageData   = $this->imageStorage->read($image->getStoragePath());
        $uploadedUrl = $this->remoteContentAssetsFacade->uploadAsset(
            $project->s3BucketName,
            $project->s3Region,
            $project->s3AccessKeyId,
            $project->s3SecretAccessKey,
            $project->s3IamRoleArn,
            $project->s3KeyPrefix,
            $fileName,
            $imageData,
            'image/png',
        );

        $uploadedFileName = $this->extractFilenameFromUrl($uploadedUrl);
        $image->setUploadedToMediaStoreAt(DateAndTimeService::getDateTimeImmutable());
        $image->setUploadedFileName($uploadedFileName);
        $this->entityManager->flush();

        return $this->json([
            'url'              => $uploadedUrl,
            'fileName'         => $fileName,
            'uploadedFileName' => $uploadedFileName,
        ]);
    }

    private function extractFilenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path !== null && $path !== false ? basename($path) : '';
    }
}
