<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Facade\Dto;

enum ConversationStatus: string
{
    case ONGOING = 'ongoing';
    case FINISHED = 'finished';
}
