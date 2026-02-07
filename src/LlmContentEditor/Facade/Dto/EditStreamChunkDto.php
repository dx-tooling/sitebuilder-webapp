<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

readonly class EditStreamChunkDto
{
    /**
     * @param 'text'|'event'|'message'|'done'|'progress' $chunkType
     */
    public function __construct(
        public string                  $chunkType,
        public ?string                 $content = null,
        public ?AgentEventDto          $event = null,
        public ?bool                   $success = null,
        public ?string                 $errorMessage = null,
        public ?ConversationMessageDto $message = null,
    ) {
    }
}
