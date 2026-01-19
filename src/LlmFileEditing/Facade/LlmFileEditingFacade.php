<?php

declare(strict_types=1);

namespace App\LlmFileEditing\Facade;

use App\LlmFileEditing\Domain\Service\TextOperationsService;
use App\LlmFileEditing\Infrastructure\Service\FileOperationsServiceInterface;

final readonly class LlmFileEditingFacade implements LlmFileEditingFacadeInterface
{
    public function __construct(
        private FileOperationsServiceInterface $fileOperationsService,
        private TextOperationsService          $textOperationsService
    ) {
    }

    public function getFolderContent(string $pathToFolder): string
    {
        return $this->fileOperationsService->listFolderContent($pathToFolder);
    }

    public function getFileContent(string $pathToFile): string
    {
        return $this->fileOperationsService->getFileContent($pathToFile);
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        $modifiedContent = $this->textOperationsService->applyDiffToFile($pathToFile, $v4aDiff);
        $this->fileOperationsService->writeFileContent($pathToFile, $modifiedContent);

        return $modifiedContent;
    }
}
