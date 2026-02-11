<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Observer;

use App\AgenticContentEditor\Facade\Dto\AgentEventDto;
use App\AgenticContentEditor\Facade\Dto\ToolInputEntryDto;
use App\LlmContentEditor\Infrastructure\AgentEventQueue;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use SplObserver;
use SplSubject;
use Stringable;

use function is_object;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class AgentEventCollectingObserver implements SplObserver
{
    public function __construct(
        private readonly AgentEventQueue $queue
    ) {
    }

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event === null || !is_object($data)) {
            return;
        }

        $dto = match ($data::class) {
            InferenceStart::class => new AgentEventDto('inference_start'),
            InferenceStop::class  => new AgentEventDto('inference_stop'),
            ToolCalling::class    => $this->mapToolCalling($data),
            ToolCalled::class     => $this->mapToolCalled($data),
            AgentError::class     => $this->mapAgentError($data),
            default               => null,
        };

        if ($dto !== null) {
            $this->queue->add($dto);
        }
    }

    private function mapToolCalling(ToolCalling $data): AgentEventDto
    {
        $toolName = $data->tool->getName();
        $inputs   = $data->tool->getInputs();

        /** @var list<ToolInputEntryDto> $toolInputs */
        $toolInputs = [];
        foreach ($inputs as $key => $value) {
            $toolInputs[] = new ToolInputEntryDto((string) $key, $this->truncateValue($value, 100));
        }

        $inputBytes = strlen((string) json_encode($inputs, JSON_THROW_ON_ERROR));

        return new AgentEventDto('tool_calling', $toolName, $toolInputs, null, null, $inputBytes, null);
    }

    private function mapToolCalled(ToolCalled $data): AgentEventDto
    {
        $toolName = $data->tool->getName();
        $result   = $data->tool->getResult();

        $resultBytes = strlen((string) $result);

        return new AgentEventDto('tool_called', $toolName, null, $this->truncateValue($result, 200), null, null, $resultBytes);
    }

    private function mapAgentError(AgentError $data): AgentEventDto
    {
        return new AgentEventDto('agent_error', null, null, null, $data->exception->getMessage());
    }

    private function truncateValue(mixed $value, int $maxLength = 100): string
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            $stringValue = '[complex value]';
        } else {
            $stringValue = (string) $value;
        }

        if (mb_strlen($stringValue) > $maxLength) {
            return mb_substr($stringValue, 0, $maxLength) . '...';
        }

        return $stringValue;
    }
}
