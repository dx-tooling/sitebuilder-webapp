<?php

declare(strict_types=1);

namespace App\LlmFileEditing\Facade;

final readonly class LlmFileEditingFacade implements LlmFileEditingFacadeInterface
{
    public function getFolderContent(string $pathToFolder): string
    {
        return '';
    }

    public function getFileContent(string $pathToFile): string
    {
        return '';
    }

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string
    {
        return '';
    }
}
