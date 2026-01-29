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

    public function testDefaultTemplateContainsRemoteContentAssetsSection(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('REMOTE CONTENT ASSETS', $template->backgroundInstructions);
        self::assertStringContainsString('list_remote_content_asset_urls', $template->backgroundInstructions);
        self::assertStringContainsString('get_remote_asset_info', $template->backgroundInstructions);
    }

    public function testDefaultTemplateContainsWorkspaceRulesSection(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('WORKSPACE RULES', $template->backgroundInstructions);
        self::assertStringContainsString('get_workspace_rules', $template->backgroundInstructions);
        self::assertStringContainsString('.sitebuilder/rules/', $template->backgroundInstructions);
    }

    public function testDefaultTemplateStepInstructionsStartsWithRulesStep(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringStartsWith('0. RULES', $template->stepInstructions);
        self::assertStringContainsString('get_workspace_rules', $template->stepInstructions);
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

    public function testDefaultTemplateOutputInstructionsContainsPreviewUrlGuidance(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('get_preview_url', $template->outputInstructions);
        self::assertStringContainsString('Markdown link', $template->outputInstructions);
    }

    public function testDefaultTemplateOutputInstructionsContainsCommitMessageGuidance(): void
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        self::assertStringContainsString('suggest_commit_message', $template->outputInstructions);
        self::assertStringContainsString('imperative mood', $template->outputInstructions);
    }
}
