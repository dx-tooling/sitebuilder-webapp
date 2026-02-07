<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Dto;

use App\LlmContentEditor\Facade\Enum\EditStreamChunkType;

readonly class EditStreamChunkDto
{
    public function __construct(
        public EditStreamChunkType     $chunkType,
        public ?string                 $content = null,
        public ?AgentEventDto          $event = null,
        public ?bool                   $success = null,
        public ?string                 $errorMessage = null,
        public ?ConversationMessageDto $message = null,
    ) {
    }
}
