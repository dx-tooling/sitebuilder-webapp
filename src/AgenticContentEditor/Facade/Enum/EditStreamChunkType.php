<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Enum;

/**
 * Chunk types yielded by the edit stream (AgenticContentEditorFacadeInterface::streamEditWithHistory).
 * String-backed for JSON/API compatibility where the value is sent to the frontend.
 */
enum EditStreamChunkType: string
{
    case Text     = 'text';
    case Event    = 'event';
    case Message  = 'message';
    case Done     = 'done';
    case Progress = 'progress';
}
