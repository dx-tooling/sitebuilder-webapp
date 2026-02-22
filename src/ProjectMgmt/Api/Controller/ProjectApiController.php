<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Api\Controller;

use App\ProjectMgmt\Domain\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectApiController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        path: '/api/projects',
        name: 'project_mgmt.api.list_names',
        methods: [Request::METHOD_GET]
    )]
    public function listNames(): JsonResponse
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p.name')
            ->from(Project::class, 'p')
            ->where('p.deletedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $names = array_map(
            static fn (array $row): string => $row['name'],
            $rows
        );

        return new JsonResponse($names);
    }
}
