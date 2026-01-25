<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Domain\Service;

use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Internal domain service for workspace CRUD and status operations.
 * Used by WorkspaceMgmtFacade and ReviewerController.
 */
final class WorkspaceService
{
    public function __construct(
        private readonly EntityManagerInterface        $entityManager,
        private readonly WorkspaceStatusGuardInterface $statusGuard,
    ) {
    }

    public function create(string $projectId): Workspace
    {
        $workspace = new Workspace($projectId);
        $this->entityManager->persist($workspace);
        $this->entityManager->flush();

        return $workspace;
    }

    public function findById(string $id): ?Workspace
    {
        return $this->entityManager->find(Workspace::class, $id);
    }

    public function findByProjectId(string $projectId): ?Workspace
    {
        /** @var Workspace|null $workspace */
        $workspace = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Workspace::class, 'w')
            ->where('w.projectId = :projectId')
            ->setParameter('projectId', $projectId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $workspace;
    }

    /**
     * Find all workspaces with a specific status.
     *
     * @return list<Workspace>
     */
    public function findByStatus(WorkspaceStatus $status): array
    {
        /** @var list<Workspace> $workspaces */
        $workspaces = $this->entityManager->createQueryBuilder()
            ->select('w')
            ->from(Workspace::class, 'w')
            ->where('w.status = :status')
            ->setParameter('status', $status)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $workspaces;
    }

    /**
     * Transition workspace to a new status.
     * Validates the transition first.
     */
    public function transitionTo(Workspace $workspace, WorkspaceStatus $newStatus): void
    {
        $currentStatus = $workspace->getStatus();
        $this->statusGuard->validateTransition($currentStatus, $newStatus);

        $workspace->setStatus($newStatus);
        $this->entityManager->flush();
    }

    /**
     * Force set workspace status (for PROBLEM recovery, etc.).
     */
    public function setStatus(Workspace $workspace, WorkspaceStatus $newStatus): void
    {
        $workspace->setStatus($newStatus);
        $this->entityManager->flush();
    }

    /**
     * Delete a workspace.
     */
    public function delete(Workspace $workspace): void
    {
        $this->entityManager->remove($workspace);
        $this->entityManager->flush();
    }
}
