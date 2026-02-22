<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Infrastructure\ChatHistory\CallbackChatHistory;
use App\ProjectMgmt\Domain\ValueObject\AgentConfigTemplate;
use App\ProjectMgmt\Facade\Enum\ProjectType;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ContentEditorAgentTest extends TestCase
{
    public function testGetBackgroundInstructionsContainsPathRules(): void
    {
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $this->createDefaultAgentConfig()
        );
        $ref = new ReflectionMethod(ContentEditorAgent::class, 'getBackgroundInstructions');
        $ref->setAccessible(true);

        /** @var list<string> $instructions */
        $instructions = $ref->invoke($agent);
        $text         = implode("\n", $instructions);

        self::assertStringContainsString('PATH RULES (critical)', $text);
        self::assertStringContainsString('Never use a path that is not under the working folder', $text);
        self::assertStringContainsString('Directory does not exist', $text);
        self::assertStringContainsString('Do not retry the same path', $text);
    }

    public function testGetStepInstructionsExploreStepIsFirst(): void
    {
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $this->createDefaultAgentConfig()
        );
        $ref = new ReflectionMethod(ContentEditorAgent::class, 'getStepInstructions');
        $ref->setAccessible(true);

        /** @var list<string> $steps */
        $steps = $ref->invoke($agent);

        self::assertNotEmpty($steps);
        $exploreStep = $steps[0];
        self::assertStringContainsString('EXPLORE', $exploreStep);
        self::assertStringContainsString('working folder', $exploreStep);
        self::assertStringContainsString('path given in the system prompt', $exploreStep);
    }

    public function testInstructionsIncludeWorkingFolderWhenSet(): void
    {
        $configWithPath = new AgentConfigDto(
            'Background',
            'Step 1',
            'Output',
            '/workspace'
        );
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $configWithPath
        );
        $instructions = $agent->instructions();
        self::assertStringContainsString('WORKING FOLDER (use for all path-based tools): /workspace', $instructions);
    }

    public function testInstructionsOmitWorkingFolderWhenNull(): void
    {
        $configWithoutPath = new AgentConfigDto('Background', 'Step 1', 'Output');
        $agent             = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $configWithoutPath
        );
        $instructions = $agent->instructions();
        self::assertStringNotContainsString('WORKING FOLDER (use for all path-based tools)', $instructions);
    }

    /**
     * When tool calls have been made, the turn activity journal summary
     * is injected into the system prompt so the LLM knows what it already did.
     *
     * @see https://github.com/dx-tooling/sitebuilder-webapp/issues/83
     */
    public function testInstructionsIncludeTurnActivitySummaryWhenToolCallsMade(): void
    {
        $history = new CallbackChatHistory([], 100000);
        $history->addMessage(new UserMessage('Build a craftsmen page'));

        // Simulate a completed tool call (list_directory)
        $tool = Tool::make('list_directory', 'List directory')
            ->setCallId('call_1')
            ->setInputs(['path' => '/workspace/src'])
            ->setResult('index.html, about.html, styles.css');
        $history->addMessage(new ToolCallMessage(null, [$tool]));
        $history->addMessage(new ToolCallResultMessage([$tool]));

        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $this->createDefaultAgentConfig()
        );
        $agent->withChatHistory($history);

        $instructions = $agent->instructions();
        self::assertStringContainsString('ACTIONS PERFORMED SO FAR THIS TURN', $instructions);
        self::assertStringContainsString('[list_directory]', $instructions);
        self::assertStringContainsString('path="/workspace/src"', $instructions);
    }

    /**
     * When no tool calls have been made yet, the activity section is omitted.
     */
    public function testInstructionsOmitActivitySectionWhenNoToolCalls(): void
    {
        $history = new CallbackChatHistory([], 100000);
        $history->addMessage(new UserMessage('Hello'));

        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $this->createDefaultAgentConfig()
        );
        $agent->withChatHistory($history);

        $instructions = $agent->instructions();
        self::assertStringNotContainsString('ACTIONS PERFORMED SO FAR THIS TURN', $instructions);
    }

    public function testAgentUsesProvidedConfig(): void
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

    public function testToolsContainFetchRemoteWebPageTool(): void
    {
        $agent = new ContentEditorAgent(
            $this->createMockWorkspaceTooling(),
            LlmModelName::defaultForContentEditor(),
            'sk-test-key',
            $this->createDefaultAgentConfig()
        );
        $ref = new ReflectionMethod(ContentEditorAgent::class, 'tools');
        $ref->setAccessible(true);

        /** @var list<ToolInterface> $tools */
        $tools = $ref->invoke($agent);

        $toolNames = array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            $tools
        );

        self::assertContains('fetch_remote_web_page', $toolNames);
    }

    private function createMockWorkspaceTooling(): WorkspaceToolingServiceInterface
    {
        return $this->createMock(WorkspaceToolingServiceInterface::class);
    }

    private function createDefaultAgentConfig(): AgentConfigDto
    {
        $template = AgentConfigTemplate::forProjectType(ProjectType::DEFAULT);

        return new AgentConfigDto(
            $template->backgroundInstructions,
            $template->stepInstructions,
            $template->outputInstructions
        );
    }
}
