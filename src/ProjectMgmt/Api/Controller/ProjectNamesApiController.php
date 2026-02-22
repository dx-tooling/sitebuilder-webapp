<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Api\Controller;

use App\ProjectMgmt\Domain\Service\ProjectService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectNamesApiController
{
    public function __construct(
        private readonly ProjectService $projectService,
    ) {
    }

    #[Route(path: '/project-names', name: 'project_mgmt.api.project_names', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['projects' => $this->projectService->findAllProjectNames()]);
    }
}
