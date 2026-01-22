<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ContentEditorAgentTest extends TestCase
{
    public function testGetBackgroundInstructionsContainsPathRules(): void
    {
        $agent = new ContentEditorAgent($this->createMockWorkspaceTooling(), LlmModelName::defaultForContentEditor());
        $ref   = new ReflectionMethod(ContentEditorAgent::class, 'getBackgroundInstructions');
        $ref->setAccessible(true);

        /** @var list<string> $instructions */
        $instructions = $ref->invoke($agent);
        $text         = implode("\n", $instructions);

        self::assertStringContainsString('PATH RULES (critical)', $text);
        self::assertStringContainsString('Never use /workspace', $text);
        self::assertStringContainsString('Directory does not exist', $text);
        self::assertStringContainsString('Do not retry the same path', $text);
    }

    public function testGetStepInstructionsExploreStepRefersToWorkingFolderFromUserMessage(): void
    {
        $agent = new ContentEditorAgent($this->createMockWorkspaceTooling(), LlmModelName::defaultForContentEditor());
        $ref   = new ReflectionMethod(ContentEditorAgent::class, 'getStepInstructions');
        $ref->setAccessible(true);

        /** @var list<string> $steps */
        $steps = $ref->invoke($agent);

        self::assertNotEmpty($steps);
        $exploreStep = $steps[0];
        self::assertStringContainsString('working folder', $exploreStep);
        self::assertStringContainsString("path from the user's message", $exploreStep);
        self::assertStringNotContainsString('workspace root folder', $exploreStep);
    }

    private function createMockWorkspaceTooling(): WorkspaceToolingServiceInterface
    {
        return $this->createMock(WorkspaceToolingServiceInterface::class);
    }
}
