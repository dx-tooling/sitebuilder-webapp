<?php

declare(strict_types=1);

namespace App\LlmFileEditing\Domain\Service;

use App\LlmFileEditing\Infrastructure\Service\FileOperationsServiceInterface;
use V4AFileEdit\ApplyDiff;

final readonly class TextOperationsService
{
    public function __construct(
        private FileOperationsServiceInterface $fileOperationsService
    ) {
    }

    public function applyDiffToFile(
        string $pathToFile,
        string $diff
    ): string {
        $originalContent = $this->fileOperationsService->getFileContent($pathToFile);
        $applyDiff       = new ApplyDiff();

        return $applyDiff->applyDiff($originalContent, $diff);
    }
}
