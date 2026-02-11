<?php

declare(strict_types=1);

namespace App\AgenticContentEditor\Facade\Enum;

enum AgenticContentEditorBackend: string
{
    case Llm         = 'llm';
    case CursorAgent = 'cursor_agent';
}
