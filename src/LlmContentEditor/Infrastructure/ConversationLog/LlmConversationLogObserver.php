<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ConversationLog;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Stringable;

use function is_object;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function sprintf;

/**
 * Observes NeuronAI agent events and writes human-readable log lines
 * to the llm_conversation Monolog channel.
 *
 * Captures tool calls, tool results, and agent errors.
 */
final readonly class LlmConversationLogObserver implements SplObserver
{
    private const int TOOL_INPUT_MAX_LENGTH = 100;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event === null || !is_object($data)) {
            return;
        }

        match ($data::class) {
            ToolCalling::class => $this->onToolCalling($data),
            ToolCalled::class  => $this->onToolCalled($data),
            AgentError::class  => $this->onAgentError($data),
            default            => null,
        };
    }

    private function onToolCalling(ToolCalling $data): void
    {
        $toolName = $data->tool->getName();
        $inputs   = $data->tool->getInputs();

        $parts = [];
        foreach ($inputs as $key => $value) {
            $parts[] = sprintf('%s=%s', (string) $key, $this->truncate($value, self::TOOL_INPUT_MAX_LENGTH));
        }

        $inputSummary = $parts !== [] ? ' (' . implode(', ', $parts) . ')' : '';
        $this->logger->info(sprintf('TOOL_CALL %s%s', $toolName, $inputSummary));
    }

    private function onToolCalled(ToolCalled $data): void
    {
        $toolName = $data->tool->getName();
        $result   = $data->tool->getResult();
        $length   = mb_strlen($this->stringify($result));

        $this->logger->info(sprintf('TOOL_RESULT %s (%d chars)', $toolName, $length));
    }

    private function onAgentError(AgentError $data): void
    {
        $this->logger->info(sprintf('ERROR %s', $data->exception->getMessage()));
    }

    private function truncate(mixed $value, int $maxLength): string
    {
        $string = $this->stringify($value);

        if (mb_strlen($string) > $maxLength) {
            return mb_substr($string, 0, $maxLength) . '…';
        }

        return $string;
    }

    private function stringify(mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '[complex value]';
        }

        return (string) $value;
    }
}
