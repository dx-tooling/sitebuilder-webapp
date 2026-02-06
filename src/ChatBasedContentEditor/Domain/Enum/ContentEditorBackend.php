<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Enum;

enum ContentEditorBackend: string
{
    case Llm         = 'llm';
    case CursorAgent = 'cursor_agent';
}
