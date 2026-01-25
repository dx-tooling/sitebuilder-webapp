<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProjectMgmt;

use App\ProjectMgmt\Domain\ValueObject\AgentConfigTemplate;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use PHPUnit\Framework\TestCase;

final class AgentConfigTemplateTest extends TestCase
{
    public function testForProjectTypeReturnsTemplateForDefaultType(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertNotEmpty($template->backgroundInstructions);
        self::assertNotEmpty($template->stepInstructions);
        self::assertNotEmpty($template->outputInstructions);
    }

    public function testDefaultTemplateContainsWorkspaceConventions(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('WORKSPACE CONVENTIONS', $template->backgroundInstructions);
        self::assertStringContainsString('Node.js', $template->backgroundInstructions);
        self::assertStringContainsString('package.json', $template->backgroundInstructions);
    }

    public function testDefaultTemplateContainsPathRules(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('PATH RULES', $template->backgroundInstructions);
        self::assertStringContainsString('working folder', $template->backgroundInstructions);
    }

    public function testDefaultTemplateStepInstructionsContainsWorkflowSteps(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('EXPLORE', $template->stepInstructions);
        self::assertStringContainsString('UNDERSTAND', $template->stepInstructions);
        self::assertStringContainsString('EDIT', $template->stepInstructions);
        self::assertStringContainsString('VERIFY', $template->stepInstructions);
    }

    public function testDefaultTemplateOutputInstructionsContainsSummaryRequirement(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('Summarize', $template->outputInstructions);
    }
}
