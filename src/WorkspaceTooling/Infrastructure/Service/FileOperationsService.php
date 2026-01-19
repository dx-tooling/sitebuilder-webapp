<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure\Service;

use App\WorkspaceTooling\Infrastructure\Service\Dto\FileInfoDto;
use RuntimeException;

use function array_filter;
use function array_slice;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function max;
use function min;
use function mkdir;
use function pathinfo;
use function preg_match;
use function scandir;
use function sprintf;
use function str_replace;

use const PATHINFO_EXTENSION;

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

    public function getFileLines(string $pathToFile, int $startLine, int $endLine): string
    {
        if (!file_exists($pathToFile)) {
            throw new RuntimeException("File does not exist: {$pathToFile}");
        }

        $content = file_get_contents($pathToFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathToFile}");
        }

        $lines      = explode("\n", $content);
        $totalLines = count($lines);

        $startLine = max(1, $startLine);
        $endLine   = min($totalLines, $endLine);

        if ($startLine > $totalLines) {
            return "File has only {$totalLines} lines.";
        }

        $selectedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $result        = [];

        foreach ($selectedLines as $index => $line) {
            $lineNumber = $startLine + $index;
            $result[]   = sprintf('%4d | %s', $lineNumber, $line);
        }

        return implode("\n", $result);
    }

    public function getFileInfo(string $pathToFile): FileInfoDto
    {
        if (!file_exists($pathToFile)) {
            throw new RuntimeException("File does not exist: {$pathToFile}");
        }

        $content = file_get_contents($pathToFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathToFile}");
        }

        $lines     = explode("\n", $content);
        $lineCount = count($lines);
        $sizeBytes = mb_strlen($content, '8bit');
        $extension = pathinfo($pathToFile, PATHINFO_EXTENSION);

        return new FileInfoDto(
            $pathToFile,
            $lineCount,
            $sizeBytes,
            $extension !== '' ? $extension : '(none)'
        );
    }

    public function searchInFile(string $pathToFile, string $searchPattern, int $contextLines = 3): string
    {
        if (!file_exists($pathToFile)) {
            throw new RuntimeException("File does not exist: {$pathToFile}");
        }

        $content = file_get_contents($pathToFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathToFile}");
        }

        $lines      = explode("\n", $content);
        $totalLines = count($lines);
        $matches    = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/' . preg_quote($searchPattern, '/') . '/i', $line) === 1) {
                $matches[] = $index;
            }
        }

        if ($matches === []) {
            return "No matches found for: {$searchPattern}";
        }

        $result   = [];
        $result[] = sprintf("Found %d match(es) for '%s':\n", count($matches), $searchPattern);

        foreach ($matches as $matchIndex) {
            $startContext = max(0, $matchIndex - $contextLines);
            $endContext   = min($totalLines - 1, $matchIndex + $contextLines);

            $result[] = sprintf('--- Match at line %d ---', $matchIndex + 1);

            for ($i = $startContext; $i <= $endContext; ++$i) {
                $prefix   = ($i === $matchIndex) ? '>>>' : '   ';
                $result[] = sprintf('%s %4d | %s', $prefix, $i + 1, $lines[$i]);
            }

            $result[] = '';
        }

        return implode("\n", $result);
    }

    public function replaceInFile(string $pathToFile, string $oldString, string $newString): string
    {
        if (!file_exists($pathToFile)) {
            throw new RuntimeException("File does not exist: {$pathToFile}");
        }

        $content = file_get_contents($pathToFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$pathToFile}");
        }

        $occurrences = mb_substr_count($content, $oldString);

        if ($occurrences === 0) {
            throw new RuntimeException('String not found in file. Make sure the old_string matches exactly, including whitespace and indentation.');
        }

        if ($occurrences > 1) {
            throw new RuntimeException("String found {$occurrences} times. The old_string must be unique. Include more surrounding context to make it unique.");
        }

        $newContent = str_replace($oldString, $newString, $content);
        $this->writeFileContent($pathToFile, $newContent);

        return "Successfully replaced string in {$pathToFile}";
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
