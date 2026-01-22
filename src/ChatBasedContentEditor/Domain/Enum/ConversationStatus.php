<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Enum;

enum ConversationStatus: string
{
    case ONGOING  = 'ongoing';
    case FINISHED = 'finished';
}
