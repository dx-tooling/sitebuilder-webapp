<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade;

use App\LlmContentEditor\Domain\Agent\ContentEditorAgent;
use App\LlmContentEditor\Facade\Dto\EditStreamChunkDto;
use App\LlmContentEditor\Infrastructure\AgentEventQueue;
use App\LlmContentEditor\Infrastructure\Observer\AgentEventCollectingObserver;
use App\WorkspaceTooling\Facade\WorkspaceToolingServiceInterface;
use Generator;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

final class LlmContentEditorFacade implements LlmContentEditorFacadeInterface
{
    public function __construct(
        private readonly WorkspaceToolingServiceInterface $workspaceTooling
    ) {
    }

    /**
     * @return Generator<EditStreamChunkDto>
     */
    public function streamEdit(string $workspacePath, string $instruction): Generator
    {
        $prompt = sprintf(
            'The working folder is: %s' . "\n\n" . 'Please perform the following task: %s',
            $workspacePath,
            $instruction
        );

        $agent = new ContentEditorAgent($this->workspaceTooling);
        $queue = new AgentEventQueue();
        $agent->attach(new AgentEventCollectingObserver($queue));

        try {
            $message = new UserMessage($prompt);
            $stream  = $agent->stream($message);

            foreach ($stream as $chunk) {
                foreach ($queue->drain() as $eventDto) {
                    yield new EditStreamChunkDto('event', null, $eventDto, null, null);
                }
                if (is_string($chunk)) {
                    yield new EditStreamChunkDto('text', $chunk, null, null, null);
                }
            }

            foreach ($queue->drain() as $eventDto) {
                yield new EditStreamChunkDto('event', null, $eventDto, null, null);
            }

            yield new EditStreamChunkDto('done', null, null, true, null);
        } catch (Throwable $e) {
            yield new EditStreamChunkDto('done', null, null, false, $e->getMessage());
        }
    }
}
