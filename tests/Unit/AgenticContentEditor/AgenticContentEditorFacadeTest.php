<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgenticContentEditor;

use App\AgenticContentEditor\Facade\AgenticContentEditorAdapterInterface;
use App\AgenticContentEditor\Facade\AgenticContentEditorFacade;
use App\AgenticContentEditor\Facade\Dto\AgentConfigDto;
use App\AgenticContentEditor\Facade\Dto\BackendModelInfoDto;
use App\AgenticContentEditor\Facade\Dto\EditStreamChunkDto;
use App\AgenticContentEditor\Facade\Enum\AgenticContentEditorBackend;
use App\AgenticContentEditor\Facade\Enum\EditStreamChunkType;
use Generator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgenticContentEditorFacadeTest extends TestCase
{
    public function testStreamEditDispatchesToCorrectAdapter(): void
    {
        $config = new AgentConfigDto('bg', 'step', 'out');

        $llmAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $llmAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::Llm
        );
        $llmAdapter->method('streamEdit')->willReturnCallback(
            static function (): Generator {
                yield new EditStreamChunkDto(EditStreamChunkType::Done, null, null, true);
            }
        );

        $cursorAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $cursorAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::CursorAgent
        );
        $cursorAdapter->expects(self::never())->method('streamEdit');

        $facade = new AgenticContentEditorFacade([$llmAdapter, $cursorAdapter]);

        $chunks = iterator_to_array($facade->streamEditWithHistory(
            AgenticContentEditorBackend::Llm,
            '/workspace',
            'Edit title',
            [],
            'key-123',
            $config
        ));

        self::assertCount(1, $chunks);
        self::assertSame(EditStreamChunkType::Done, $chunks[0]->chunkType);
    }

    public function testBuildAgentContextDumpDispatchesToCorrectAdapter(): void
    {
        $config = new AgentConfigDto('bg', 'step', 'out');

        $llmAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $llmAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::Llm
        );
        $llmAdapter->method('buildAgentContextDump')->willReturn('LLM context dump');

        $cursorAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $cursorAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::CursorAgent
        );
        $cursorAdapter->method('buildAgentContextDump')->willReturn('Cursor context dump');

        $facade = new AgenticContentEditorFacade([$llmAdapter, $cursorAdapter]);

        self::assertSame('LLM context dump', $facade->buildAgentContextDump(
            AgenticContentEditorBackend::Llm,
            'Edit title',
            [],
            $config
        ));

        self::assertSame('Cursor context dump', $facade->buildAgentContextDump(
            AgenticContentEditorBackend::CursorAgent,
            'Edit title',
            [],
            $config
        ));
    }

    public function testGetBackendModelInfoDispatchesToCorrectAdapter(): void
    {
        $llmInfo    = new BackendModelInfoDto('gpt-5.2', 128_000, 1.75, 14.0);
        $cursorInfo = new BackendModelInfoDto('cursor-agent', 200_000);

        $llmAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $llmAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::Llm
        );
        $llmAdapter->method('getBackendModelInfo')->willReturn($llmInfo);

        $cursorAdapter = $this->createMock(AgenticContentEditorAdapterInterface::class);
        $cursorAdapter->method('supports')->willReturnCallback(
            static fn (AgenticContentEditorBackend $b): bool => $b === AgenticContentEditorBackend::CursorAgent
        );
        $cursorAdapter->method('getBackendModelInfo')->willReturn($cursorInfo);

        $facade = new AgenticContentEditorFacade([$llmAdapter, $cursorAdapter]);

        $result = $facade->getBackendModelInfo(AgenticContentEditorBackend::Llm);
        self::assertSame('gpt-5.2', $result->modelName);
        self::assertSame(128_000, $result->maxContextTokens);
        self::assertSame(1.75, $result->inputCostPer1M);
        self::assertSame(14.0, $result->outputCostPer1M);

        $result = $facade->getBackendModelInfo(AgenticContentEditorBackend::CursorAgent);
        self::assertSame('cursor-agent', $result->modelName);
        self::assertSame(200_000, $result->maxContextTokens);
        self::assertNull($result->inputCostPer1M);
        self::assertNull($result->outputCostPer1M);
    }

    public function testThrowsWhenNoAdapterSupportsBackend(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No content editor adapter registered for backend');

        $facade = new AgenticContentEditorFacade([]);

        // Force generator to execute by iterating
        iterator_to_array($facade->streamEditWithHistory(
            AgenticContentEditorBackend::CursorAgent,
            '/workspace',
            'Edit',
            [],
            'key',
            new AgentConfigDto('', '', '')
        ));
    }
}
