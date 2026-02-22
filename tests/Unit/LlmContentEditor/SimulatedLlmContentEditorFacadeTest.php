<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Facade\Dto\AgentConfigDto;
use App\LlmContentEditor\Facade\Exception\CancelledException;
use App\LlmContentEditor\TestHarness\SimulatedLlmContentEditorFacade;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

final class SimulatedLlmContentEditorFacadeTest extends TestCase
{
    /**
     * When the isCancelled callback returns true, the facade must propagate
     * CancelledException so the handler can transition the session to Cancelled.
     */
    public function testStreamEditWithHistoryThrowsCancelledExceptionWhenCallbackReturnsTrue(): void
    {
        $facade = new SimulatedLlmContentEditorFacade();

        $generator = $facade->streamEditWithHistory(
            '/workspace',
            'update the hero title',
            [],
            'simulated-api-key',
            new AgentConfigDto('', '', '', '/workspace'),
            'en',
            fn (): bool => true,
        );

        $this->expectException(CancelledException::class);

        iterator_to_array($generator);
    }

    /**
     * The [simulate_cancel_always] instruction marker must always trigger
     * CancelledException regardless of the isCancelled callback, allowing
     * integration tests to exercise the handler's cancellation catch block.
     */
    public function testStreamEditWithHistoryThrowsCancelledExceptionForSimulateCancelAlwaysMarker(): void
    {
        $facade = new SimulatedLlmContentEditorFacade();

        $generator = $facade->streamEditWithHistory(
            '/workspace',
            'do something [simulate_cancel_always]',
            [],
            'simulated-api-key',
            new AgentConfigDto('', '', '', '/workspace'),
        );

        $this->expectException(CancelledException::class);

        iterator_to_array($generator);
    }

    /**
     * When the isCancelled callback returns false, the facade must complete
     * normally and yield a successful Done chunk.
     */
    public function testStreamEditWithHistoryCompletesNormallyWhenCallbackReturnsFalse(): void
    {
        $facade = new SimulatedLlmContentEditorFacade();

        $generator = $facade->streamEditWithHistory(
            '/workspace',
            'update the hero title',
            [],
            'simulated-api-key',
            new AgentConfigDto('', '', '', '/workspace'),
            'en',
            fn (): bool => false,
        );

        $chunks = iterator_to_array($generator);

        $lastChunk = $chunks[count($chunks) - 1];
        self::assertTrue($lastChunk->success, 'Last chunk must report success when not cancelled.');
    }
}
