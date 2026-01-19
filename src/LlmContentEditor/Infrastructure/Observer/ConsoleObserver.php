<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Observer;

use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use SplObserver;
use SplSubject;
use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

use function is_object;
use function mb_strlen;
use function mb_substr;

final class ConsoleObserver implements SplObserver
{
    public function __construct(
        private readonly OutputInterface $output
    ) {
    }

    public function update(SplSubject $subject, ?string $event = null, mixed $data = null): void
    {
        if ($event === null || !is_object($data)) {
            return;
        }

        match ($data::class) {
            InferenceStart::class => $this->onInferenceStart($data),
            InferenceStop::class  => $this->onInferenceStop(),
            ToolCalling::class    => $this->onToolCalling($data),
            ToolCalled::class     => $this->onToolCalled($data),
            AgentError::class     => $this->onAgentError($data),
            default               => null,
        };
    }

    private function onInferenceStart(InferenceStart $data): void
    {
        $this->output->writeln('<comment>  → Sending to LLM...</comment>');
    }

    private function onInferenceStop(): void
    {
        $this->output->writeln('<comment>  ← LLM response received</comment>');
    }

    private function onToolCalling(ToolCalling $data): void
    {
        $toolName = $data->tool->getName();
        $inputs   = $data->tool->getInputs();

        $this->output->writeln('');
        $this->output->writeln("<info>  ▶ Calling tool:</info> <fg=cyan>{$toolName}</>");

        foreach ($inputs as $key => $value) {
            $displayValue = $this->truncateValue($value);
            $this->output->writeln("    <fg=gray>{$key}:</> {$displayValue}");
        }
    }

    private function onToolCalled(ToolCalled $data): void
    {
        $toolName = $data->tool->getName();
        $result   = $data->tool->getResult();

        $displayResult = $this->truncateValue($result, 200);
        $this->output->writeln("<info>  ◀ Tool result:</info> {$displayResult}");
    }

    private function onAgentError(AgentError $data): void
    {
        $this->output->writeln('');
        $this->output->writeln("<error>  ✖ Agent error: {$data->exception->getMessage()}</error>");
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
