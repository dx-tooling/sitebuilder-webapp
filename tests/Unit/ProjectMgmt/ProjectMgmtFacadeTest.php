<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProjectMgmt;

use App\ProjectMgmt\Domain\Service\ProjectService;
use App\ProjectMgmt\Facade\ProjectMgmtFacade;
use App\WorkspaceMgmt\Infrastructure\Service\GitHubUrlServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProjectMgmtFacadeTest extends TestCase
{
    public function testAbbreviateApiKeyShortensLongKeys(): void
    {
        $facade = $this->createFacade();
        $ref    = new ReflectionMethod(ProjectMgmtFacade::class, 'abbreviateApiKey');
        $ref->setAccessible(true);

        // Long key (> 15 chars)
        $result = $ref->invoke($facade, 'sk-proj-abcdefghijklmnopqrstuvwxyz');

        self::assertSame('sk-pro...uvwxyz', $result);
    }

    public function testAbbreviateApiKeyPreservesShortKeys(): void
    {
        $facade = $this->createFacade();
        $ref    = new ReflectionMethod(ProjectMgmtFacade::class, 'abbreviateApiKey');
        $ref->setAccessible(true);

        // Short key (<= 15 chars)
        $result = $ref->invoke($facade, 'sk-short-key');

        self::assertSame('sk-short-key', $result);
    }

    public function testAbbreviateApiKeyHandlesExactly15Chars(): void
    {
        $facade = $this->createFacade();
        $ref    = new ReflectionMethod(ProjectMgmtFacade::class, 'abbreviateApiKey');
        $ref->setAccessible(true);

        // Exactly 15 chars should not be abbreviated
        $result = $ref->invoke($facade, '123456789012345');

        self::assertSame('123456789012345', $result);
    }

    public function testAbbreviateApiKeyHandles16Chars(): void
    {
        $facade = $this->createFacade();
        $ref    = new ReflectionMethod(ProjectMgmtFacade::class, 'abbreviateApiKey');
        $ref->setAccessible(true);

        // 16 chars should be abbreviated
        $result = $ref->invoke($facade, '1234567890123456');

        self::assertSame('123456...123456', $result);
    }

    private function createFacade(): ProjectMgmtFacade
    {
        $entityManager    = $this->createMock(EntityManagerInterface::class);
        $gitHubUrlService = $this->createMock(GitHubUrlServiceInterface::class);
        $projectService   = new ProjectService($entityManager);

        return new ProjectMgmtFacade($entityManager, $gitHubUrlService, $projectService);
    }
}
