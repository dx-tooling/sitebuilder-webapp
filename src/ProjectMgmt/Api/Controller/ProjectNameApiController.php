<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Api\Controller;

use App\ProjectMgmt\Api\Dto\ProjectNameDto;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\ProjectMgmt\Facade\ProjectMgmtFacadeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/api/project-names',
    name: 'project_mgmt.api.project_names',
    methods: [Request::METHOD_GET]
)]
final class ProjectNameApiController extends AbstractController
{
    public function __construct(
        private readonly ProjectMgmtFacadeInterface $projectMgmtFacade,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $projects = $this->projectMgmtFacade->getProjectInfos();
        $names    = array_map(
            static fn (ProjectInfoDto $p) => new ProjectNameDto($p->id, $p->name),
            $projects
        );

        return $this->json($names);
    }
}
