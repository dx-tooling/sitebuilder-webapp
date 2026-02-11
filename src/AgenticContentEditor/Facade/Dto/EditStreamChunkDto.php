<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Dto;

use App\AgenticContentEditor\Facade\Enum\EditStreamChunkType;

readonly class EditStreamChunkDto
{
    public function __construct(
        public EditStreamChunkType     $chunkType,
        public ?string                 $content = null,
        public ?AgentEventDto          $event = null,
        public ?bool                   $success = null,
        public ?string                 $errorMessage = null,
        public ?ConversationMessageDto $message = null,
        /** Opaque session state for the backend (e.g. Cursor session ID). Set on Done chunks; passed back on next turn. */
        public ?string                 $backendSessionState = null,
    ) {
    }
}
