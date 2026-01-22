<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Adapter;

use App\ChatBasedContentEditor\Infrastructure\Dto\DistFileDto;

/**
 * Scans workspace dist folders for HTML files.
 */
interface DistFileScannerInterface
{
    /**
     * Scan the dist folder of a workspace for HTML files.
     *
     * @return list<DistFileDto>
     */
    public function scanDistHtmlFiles(string $workspaceId, string $workspacePath): array;
}
