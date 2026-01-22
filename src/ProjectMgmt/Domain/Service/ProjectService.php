<?php

declare(strict_types=1);

namespace App\ProjectMgmt\Domain\Service;

use App\ProjectMgmt\Domain\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Internal domain service for project CRUD operations.
 * Used by ProjectController within the same vertical.
 */
final class ProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function create(string $name, string $gitUrl, string $githubToken): Project
    {
        $project = new Project($name, $gitUrl, $githubToken);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    public function update(Project $project, string $name, string $gitUrl, string $githubToken): void
    {
        $project->setName($name);
        $project->setGitUrl($gitUrl);
        $project->setGithubToken($githubToken);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Project
    {
        return $this->entityManager->find(Project::class, $id);
    }

    /**
     * @return list<Project>
     */
    public function findAll(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Project::class, 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $projects;
    }
}
