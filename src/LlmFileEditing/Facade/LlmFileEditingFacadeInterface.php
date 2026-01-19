<?php

declare(strict_types=1);

namespace App\LlmFileEditing\Facade;

interface LlmFileEditingFacadeInterface
{
    public function getFolderContent(string $pathToFolder): string;

    public function getFileContent(string $pathToFile): string;

    public function applyV4aDiffToFile(string $pathToFile, string $v4aDiff): string;
}
