<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Domain\Enum;

enum EditSessionStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
}
