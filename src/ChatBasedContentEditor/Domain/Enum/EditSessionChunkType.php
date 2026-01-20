<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Enum;

enum EditSessionChunkType: string
{
    case Text  = 'text';
    case Event = 'event';
    case Done  = 'done';
}
