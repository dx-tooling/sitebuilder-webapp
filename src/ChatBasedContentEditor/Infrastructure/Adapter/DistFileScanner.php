<?php

declare(strict_types=1);

namespace App\ChatBasedContentEditor\Infrastructure\Adapter;

use Symfony\Component\Finder\Finder;

use function is_dir;
use function str_replace;

/**
 * Scans workspace dist folders for HTML files using Symfony Finder.
 */
final class DistFileScanner implements DistFileScannerInterface
{
    public function scanDistHtmlFiles(string $workspaceId, string $workspacePath): array
    {
        $distPath = $workspacePath . '/dist';

        if (!is_dir($distPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->in($distPath)->name('*.html')->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $relativePath = str_replace($distPath . '/', '', $file->getPathname());
            $files[]      = [
                'path' => $relativePath,
                'url'  => '/workspaces/' . $workspaceId . '/dist/' . $relativePath,
            ];
        }

        return $files;
    }
}
