<?php

declare(strict_types=1);

namespace App\Tests\Unit\CursorAgentContentEditor;

use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\ConversationMessageDto;
use App\CursorAgentContentEditor\Infrastructure\CursorAgentContentEditorAdapter;
use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use PHPUnit\Framework\TestCase;

final class CursorAgentContentEditorAdapterTest extends TestCase
{
    private CursorAgentContentEditorAdapter $adapter;

    protected function setUp(): void
    {
        $workspaceTooling = $this->createMock(WorkspaceToolingServiceInterface::class);
        $workspaceTooling->method('getWorkspaceRules')->willReturn('{}');

        $executionContext = $this->createMock(AgentExecutionContextInterface::class);

        $this->adapter = new CursorAgentContentEditorAdapter($workspaceTooling, $executionContext, 'test-cursor-image');
    }

    public function testBuildAgentContextDumpIncludesSystemContextOnFirstMessage(): void
    {
        $config = new AgentConfigDto('Background info', 'Step info', 'Output info');

        $dump = $this->adapter->buildAgentContextDump('Edit the hero section', [], $config);

        self::assertStringContainsString('CURSOR AGENT CONTEXT', $dump);
        self::assertStringContainsString('SYSTEM CONTEXT', $dump);
        self::assertStringContainsString('Background info', $dump);
        self::assertStringContainsString('Step info', $dump);
        self::assertStringContainsString('Output info', $dump);
        self::assertStringContainsString('Edit the hero section', $dump);
    }

    public function testBuildAgentContextDumpOmitsSystemContextOnFollowUp(): void
    {
        $config   = new AgentConfigDto('Background info', 'Step info', 'Output info');
        $messages = [
            new ConversationMessageDto('user', '{"content":"First instruction"}'),
            new ConversationMessageDto('assistant', '{"content":"Done."}'),
        ];

        $dump = $this->adapter->buildAgentContextDump('Follow-up instruction', $messages, $config);

        self::assertStringNotContainsString('SYSTEM CONTEXT', $dump);
        self::assertStringContainsString('CONVERSATION HISTORY', $dump);
        self::assertStringContainsString('Follow-up instruction', $dump);
    }

    public function testBuildAgentContextDumpIncludesConversationHistory(): void
    {
        $config   = new AgentConfigDto('', '', '');
        $messages = [
            new ConversationMessageDto('user', '{"content":"Make the title bigger"}'),
            new ConversationMessageDto('assistant', '{"content":"I updated the title."}'),
        ];

        $dump = $this->adapter->buildAgentContextDump('Now change the color', $messages, $config);

        self::assertStringContainsString('Conversation so far:', $dump);
        self::assertStringContainsString('Make the title bigger', $dump);
        self::assertStringContainsString('I updated the title.', $dump);
    }

    public function testBuildAgentContextDumpIncludesWorkspaceFolderInSystemContext(): void
    {
        $config = new AgentConfigDto('', '', '');

        $dump = $this->adapter->buildAgentContextDump('Some task', [], $config);

        self::assertStringContainsString('The working folder is: /workspace', $dump);
    }

    public function testBuildAgentContextDumpIncludesBuildSyncInstruction(): void
    {
        $config = new AgentConfigDto('', '', '');

        $dump = $this->adapter->buildAgentContextDump('Some task', [], $config);

        self::assertStringContainsString('npm run build', $dump);
        self::assertStringContainsString('Keep Source and Dist in Sync', $dump);
    }

    public function testBuildAgentContextDumpSkipsEmptyInstructions(): void
    {
        $config = new AgentConfigDto('', '', '');

        $dump = $this->adapter->buildAgentContextDump('Some task', [], $config);

        self::assertStringNotContainsString('Background Instructions', $dump);
        self::assertStringNotContainsString('Step-by-Step Instructions', $dump);
        self::assertStringNotContainsString('Output Instructions', $dump);
    }

    public function testGetBackendModelInfoReturnsCursorDefaults(): void
    {
        $info = $this->adapter->getBackendModelInfo();

        self::assertSame('cursor-agent', $info->modelName);
        self::assertSame(200_000, $info->maxContextTokens);
        self::assertNull($info->inputCostPer1M);
        self::assertNull($info->outputCostPer1M);
    }

    public function testBuildAgentContextDumpOnlyIncludesNonEmptyInstructionSections(): void
    {
        $config = new AgentConfigDto('', 'Do step one then step two', '');

        $dump = $this->adapter->buildAgentContextDump('Some task', [], $config);

        self::assertStringNotContainsString('Background Instructions', $dump);
        self::assertStringContainsString('Step-by-Step Instructions', $dump);
        self::assertStringContainsString('Do step one then step two', $dump);
        self::assertStringNotContainsString('Output Instructions', $dump);
    }
}
