<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ContentEditorAgentTest extends TestCase
{
    public function testGetBackgroundInstructionsContainsPathRules(): void
    {
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key'
        );
        $ref = new ReflectionMethod(ContentEditorAgent::class, 'getBackgroundInstructions');
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
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key'
        );
        $ref = new ReflectionMethod(ContentEditorAgent::class, 'getStepInstructions');
        $ref->setAccessible(true);

        /** @var list<string> $steps */
        $steps = $ref->invoke($agent);

        self::assertNotEmpty($steps);
        $exploreStep = $steps[0];
        self::assertStringContainsString('working folder', $exploreStep);
        self::assertStringContainsString("path from the user's message", $exploreStep);
        self::assertStringNotContainsString('workspace root folder', $exploreStep);
    }

    public function testAgentUsesCustomConfigWhenProvided(): void
    {
        $customConfig = new AgentConfigDto(
            "Custom background\nwith multiple lines",
            "Custom step 1\nCustom step 2",
            'Custom output'
        );

        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $customConfig
        );

        $bgRef = new ReflectionMethod(ContentEditorAgent::class, 'getBackgroundInstructions');
        $bgRef->setAccessible(true);
        /** @var list<string> $bgInstructions */
        $bgInstructions = $bgRef->invoke($agent);

        self::assertSame(['Custom background', 'with multiple lines'], $bgInstructions);

        $stepRef = new ReflectionMethod(ContentEditorAgent::class, 'getStepInstructions');
        $stepRef->setAccessible(true);
        /** @var list<string> $stepInstructions */
        $stepInstructions = $stepRef->invoke($agent);

        self::assertSame(['Custom step 1', 'Custom step 2'], $stepInstructions);

        $outputRef = new ReflectionMethod(ContentEditorAgent::class, 'getOutputInstructions');
        $outputRef->setAccessible(true);
        /** @var list<string> $outputInstructions */
        $outputInstructions = $outputRef->invoke($agent);

        self::assertSame(['Custom output'], $outputInstructions);
    }

    public function testAgentUsesDefaultConfigWhenNoCustomConfigProvided(): void
    {
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key'
            // No custom config
        );

        $ref = new ReflectionMethod(ContentEditorAgent::class, 'getBackgroundInstructions');
        $ref->setAccessible(true);
        /** @var list<string> $instructions */
        $instructions = $ref->invoke($agent);

        // Should contain default instructions
        self::assertNotEmpty($instructions);
        $text = implode("\n", $instructions);
        self::assertStringContainsString('WORKSPACE CONVENTIONS', $text);
    }

    private function createMockWorkspaceTooling(): WorkspaceToolingServiceInterface
    {
        return $this->createMock(WorkspaceToolingServiceInterface::class);
    }
}
