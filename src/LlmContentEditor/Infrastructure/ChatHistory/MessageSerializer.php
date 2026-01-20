<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\ChatHistory;

use App\LlmContentEditor\Facade\Dto\ConversationMessageDto;
use JsonException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

use function array_map;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Handles serialization and deserialization of NeuronAI messages to/from DTOs.
 */
final class MessageSerializer
{
    /**
     * Convert a NeuronAI Message to a ConversationMessageDto.
     *
     * @throws JsonException
     */
    public function toDto(Message $message): ConversationMessageDto
    {
        $role        = $this->determineRole($message);
        $contentJson = $this->serializeContent($message);

        return new ConversationMessageDto($role, $contentJson);
    }

    /**
     * Convert a ConversationMessageDto back to a NeuronAI Message.
     *
     * @throws JsonException
     */
    public function fromDto(ConversationMessageDto $dto): Message
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($dto->contentJson, true, 512, JSON_THROW_ON_ERROR);

        /** @var string $content */
        $content = $data['content'] ?? '';

        return match ($dto->role) {
            'user'      => new UserMessage($content),
            'assistant' => new AssistantMessage($content !== '' ? $content : null),
            'tool_call' => $this->deserializeToolCall($data),
            default     => $this->deserializeToolCallResult($data),
        };
    }

    /**
     * @return 'user'|'assistant'|'tool_call'|'tool_call_result'
     */
    private function determineRole(Message $message): string
    {
        if ($message instanceof ToolCallMessage) {
            return 'tool_call';
        }

        if ($message instanceof ToolCallResultMessage) {
            return 'tool_call_result';
        }

        return match ($message->getRole()) {
            MessageRole::USER->value      => 'user',
            MessageRole::ASSISTANT->value => 'assistant',
            default                       => 'assistant',
        };
    }

    /**
     * @throws JsonException
     */
    private function serializeContent(Message $message): string
    {
        if ($message instanceof ToolCallMessage) {
            return $this->serializeToolCall($message);
        }

        if ($message instanceof ToolCallResultMessage) {
            return $this->serializeToolCallResult($message);
        }

        return json_encode(['content' => $message->getContent()], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private function serializeToolCall(ToolCallMessage $message): string
    {
        $tools = [];
        foreach ($message->getTools() as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }
            $tools[] = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputs'      => $tool->getInputs(),
                'callId'      => $tool->getCallId(),
            ];
        }

        return json_encode([
            'content' => $message->getContent(),
            'tools'   => $tools,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private function serializeToolCallResult(ToolCallResultMessage $message): string
    {
        $tools = [];
        foreach ($message->getTools() as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }
            $tools[] = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputs'      => $tool->getInputs(),
                'callId'      => $tool->getCallId(),
                'result'      => $tool->getResult(),
            ];
        }

        return json_encode(['tools' => $tools], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserializeToolCall(array $data): ToolCallMessage
    {
        /** @var list<array{name: string, description: string, inputs: array<string, mixed>, callId: string|null}> $toolsData */
        $toolsData = $data['tools'] ?? [];

        $tools = array_map(
            static fn (array $toolData): Tool => Tool::make($toolData['name'], $toolData['description'])
                ->setInputs($toolData['inputs'])
                ->setCallId($toolData['callId'] ?? null),
            $toolsData
        );

        /** @var string|null $content */
        $content = $data['content'] ?? null;

        return new ToolCallMessage($content, $tools);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserializeToolCallResult(array $data): ToolCallResultMessage
    {
        /** @var list<array{name: string, description: string, inputs: array<string, mixed>, callId: string, result: string}> $toolsData */
        $toolsData = $data['tools'] ?? [];

        $tools = array_map(
            static fn (array $toolData): Tool => Tool::make($toolData['name'], $toolData['description'])
                ->setInputs($toolData['inputs'])
                ->setCallId($toolData['callId'])
                ->setResult($toolData['result']),
            $toolsData
        );

        return new ToolCallResultMessage($tools);
    }
}
