<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\WireLog;

use App\WorkspaceTooling\Facade\AgentExecutionContextInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that enriches llm_wire log records with conversation
 * and workspace context from the current agent execution.
 */
final readonly class LlmWireLogProcessor implements ProcessorInterface
{
    public function __construct(
        private AgentExecutionContextInterface $executionContext,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $conversationId = $this->executionContext->getConversationId();
        if ($conversationId !== null) {
            $extra['conversationId'] = $conversationId;
        }

        $workspaceId = $this->executionContext->getWorkspaceId();
        if ($workspaceId !== null) {
            $extra['workspaceId'] = $workspaceId;
        }

        return $record->with(extra: $extra);
    }
}
