<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Enum;

enum PhotoImageStatus: string
{
    case Pending    = 'pending';
    case Generating = 'generating';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
