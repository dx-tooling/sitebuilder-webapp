<?php

declare(strict_types=1);

namespace Tests\Unit\WorkspaceMgmt;

use App\WorkspaceMgmt\Domain\Entity\Workspace;
use App\WorkspaceMgmt\Domain\Service\WorkspaceService;
use App\WorkspaceMgmt\Domain\Service\WorkspaceStatusGuard;
use App\WorkspaceMgmt\Domain\Service\WorkspaceStatusGuardInterface;
use App\WorkspaceMgmt\Facade\Enum\WorkspaceStatus;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkspaceServiceTest extends TestCase
{
    public function testTransitionToValidatesViaStatusGuard(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = $this->createMock(WorkspaceStatusGuardInterface::class);
        $statusGuard->expects($this->once())
            ->method('validateTransition')
            ->with(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION, WorkspaceStatus::IN_CONVERSATION);

        $service = new WorkspaceService($entityManager, $statusGuard);
        $service->transitionTo($workspace, WorkspaceStatus::IN_CONVERSATION);

        self::assertSame(WorkspaceStatus::IN_CONVERSATION, $workspace->getStatus());
    }

    public function testTransitionToThrowsWhenTransitionIsInvalid(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::IN_REVIEW);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        // Use real status guard to test actual validation
        $statusGuard = new WorkspaceStatusGuard();

        $service = new WorkspaceService($entityManager, $statusGuard);

        $this->expectException(InvalidArgumentException::class);

        $service->transitionTo($workspace, WorkspaceStatus::IN_CONVERSATION);
    }

    public function testTransitionToInConversationToAvailableForConversation(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::IN_CONVERSATION);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = new WorkspaceStatusGuard();

        $service = new WorkspaceService($entityManager, $statusGuard);
        $service->transitionTo($workspace, WorkspaceStatus::AVAILABLE_FOR_CONVERSATION);

        self::assertSame(WorkspaceStatus::AVAILABLE_FOR_CONVERSATION, $workspace->getStatus());
    }

    public function testTransitionToInConversationToInReview(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::IN_CONVERSATION);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = new WorkspaceStatusGuard();

        $service = new WorkspaceService($entityManager, $statusGuard);
        $service->transitionTo($workspace, WorkspaceStatus::IN_REVIEW);

        self::assertSame(WorkspaceStatus::IN_REVIEW, $workspace->getStatus());
    }

    public function testTransitionToInReviewToMerged(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::IN_REVIEW);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = new WorkspaceStatusGuard();

        $service = new WorkspaceService($entityManager, $statusGuard);
        $service->transitionTo($workspace, WorkspaceStatus::MERGED);

        self::assertSame(WorkspaceStatus::MERGED, $workspace->getStatus());
    }

    public function testSetStatusBypassesValidation(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::IN_CONVERSATION);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        // Status guard should NOT be called for setStatus
        $statusGuard = $this->createMock(WorkspaceStatusGuardInterface::class);
        $statusGuard->expects($this->never())->method('validateTransition');

        $service = new WorkspaceService($entityManager, $statusGuard);
        $service->setStatus($workspace, WorkspaceStatus::PROBLEM);

        self::assertSame(WorkspaceStatus::PROBLEM, $workspace->getStatus());
    }

    public function testSetStatusAllowsAnyStatusWithoutValidation(): void
    {
        $workspace = $this->createWorkspace('ws-1', 'proj-1', WorkspaceStatus::PROBLEM);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = $this->createMock(WorkspaceStatusGuardInterface::class);

        $service = new WorkspaceService($entityManager, $statusGuard);
        // This would be invalid via transitionTo, but setStatus allows it
        $service->setStatus($workspace, WorkspaceStatus::AVAILABLE_FOR_SETUP);

        self::assertSame(WorkspaceStatus::AVAILABLE_FOR_SETUP, $workspace->getStatus());
    }

    public function testCreateCreatesWorkspaceWithAvailableForSetupStatus(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $statusGuard = $this->createMock(WorkspaceStatusGuardInterface::class);

        $service   = new WorkspaceService($entityManager, $statusGuard);
        $workspace = $service->create('project-123');

        self::assertSame('project-123', $workspace->getProjectId());
        self::assertSame(WorkspaceStatus::AVAILABLE_FOR_SETUP, $workspace->getStatus());
    }

    /**
     * Helper to create a Workspace with reflection to set the ID.
     */
    private function createWorkspace(string $id, string $projectId, WorkspaceStatus $status): Workspace
    {
        $workspace = new Workspace($projectId);
        $workspace->setStatus($status);

        // Use reflection to set the ID since it's normally set by Doctrine
        $reflection = new ReflectionClass($workspace);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($workspace, $id);

        return $workspace;
    }
}
