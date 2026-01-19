<?php

declare(strict_types=1);

namespace App\LlmFileEditing\Infrastructure\Service;

use RuntimeException;

final class FileOperationsService implements FileOperationsServiceInterface
{
    public function listFolderContent(string $pathToFolder): string
    {
        if (!is_dir($pathToFolder)) {
            throw new RuntimeException("Directory does not exist: {$pathToFolder}");
        }

        $files = scandir($pathToFolder);
        if ($files === false) {
            throw new RuntimeException("Failed to list directory: {$pathToFolder}");
        }

        return implode("\n", array_filter($files, fn (string $file) => $file !== '.' && $file !== '..'));
    }

    public function getFileContent(string $pathToFile): string
    {
        if (!file_exists($pathToFile)) {
            throw new RuntimeException("File does not exist: {$pathToFile}");
        }

        $content = file_get_contents($pathToFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathToFile}");
        }

        return $content;
    }

    public function writeFileContent(string $pathToFile, string $fileContent): void
    {
        $directory = dirname($pathToFile);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: {$directory}");
            }
        }

        $result = file_put_contents($pathToFile, $fileContent);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$pathToFile}");
        }
    }
}
