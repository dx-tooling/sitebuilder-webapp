<?php

declare(strict_types=1);

namespace App\LlmIntegration\Facade;

use App\LlmIntegration\Facade\Dto\LlmMessageDto;
use App\LlmIntegration\Facade\Dto\LlmResponseDto;
use App\LlmIntegration\Facade\Dto\LlmStreamDto;
use App\LlmIntegration\Facade\Dto\ToolCallDto;
use App\LlmIntegration\Facade\Dto\ToolDefinitionDto;
use App\LlmIntegration\Facade\Dto\ToolResultDto;

interface LlmIntegrationFacadeInterface
{
    /**
     * @param list<LlmMessageDto>     $messages
     * @param list<ToolDefinitionDto> $tools
     */
    public function sendPrompt(string $apiKey, array $messages, array $tools): LlmResponseDto;

    /**
     * @param list<LlmMessageDto>     $messages
     * @param list<ToolDefinitionDto> $tools
     */
    public function getPromptStream(string $apiKey, array $messages, array $tools): LlmStreamDto;

    /**
     * @return list<ToolDefinitionDto>
     */
    public function getAvailableTools(): array;

    public function executeToolCall(string $workspaceId, ToolCallDto $toolCall): ToolResultDto;
}
