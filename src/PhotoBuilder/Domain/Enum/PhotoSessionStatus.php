<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Domain\Enum;

enum PhotoSessionStatus: string
{
    case GeneratingPrompts = 'generating_prompts';
    case PromptsReady      = 'prompts_ready';
    case GeneratingImages  = 'generating_images';
    case ImagesReady       = 'images_ready';
    case Failed            = 'failed';
}
