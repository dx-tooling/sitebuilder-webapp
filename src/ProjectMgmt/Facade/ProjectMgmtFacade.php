<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Facade;

use App\ProjectMgmt\Domain\Entity\Project;
use App\ProjectMgmt\Facade\Dto\ProjectInfoDto;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Facade implementation for project management.
 * Exposes read-only operations to other verticals.
 */
final class ProjectMgmtFacade implements ProjectMgmtFacadeInterface
{
    public function __construct(
        private readonly EntityManagerInterface    $entityManager,
        private readonly GitHubUrlServiceInterface $gitHubUrlService,
    ) {
    }

    public function getProjectInfo(string $id): ProjectInfoDto
    {
        $project = $this->entityManager->find(Project::class, $id);

        if ($project === null) {
            throw new RuntimeException('Project not found: ' . $id);
        }

        return $this->toDto($project);
    }

    /**
     * @return list<ProjectInfoDto>
     */
    public function getProjectInfos(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $dtos = [];
        foreach ($projects as $project) {
            $dtos[] = $this->toDto($project);
        }

        return $dtos;
    }

    private function toDto(Project $project): ProjectInfoDto
    {
        $id = $project->getId();
        if ($id === null) {
            throw new RuntimeException('Project ID cannot be null');
        }

        $githubUrl = $this->gitHubUrlService->getRepositoryUrl($project->getGitUrl());

        return new ProjectInfoDto(
            $id,
            $project->getName(),
            $project->getGitUrl(),
            $project->getGithubToken(),
            $project->getProjectType(),
            $githubUrl,
        );
    }
}
