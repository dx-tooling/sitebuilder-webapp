<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceTooling\Infrastructure\Service;

use App\WorkspaceTooling\Infrastructure\Service\FileOperationsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileOperationsServiceTest extends TestCase
{
    private FileOperationsService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->service = new FileOperationsService();
        $this->tempDir = sys_get_temp_dir() . '/file_ops_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function createTestFile(string $filename, string $content): string
    {
        $path = $this->tempDir . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    #[Test]
    public function listFolderContentReturnsErrorWhenDirectoryDoesNotExist(): void
    {
        $result = $this->service->listFolderContent($this->tempDir . '/nonexistent');

        self::assertStringContainsString('Error:', $result);
        self::assertStringContainsString('does not exist', $result);
        self::assertStringContainsString('create_directory', $result);
    }

    #[Test]
    public function getFileContentReturnsErrorWhenFileDoesNotExist(): void
    {
        $result = $this->service->getFileContent($this->tempDir . '/nonexistent.txt');

        self::assertStringContainsString('Error:', $result);
        self::assertStringContainsString('does not exist', $result);
    }

    #[Test]
    public function getFileLinesReturnsErrorWhenFileDoesNotExist(): void
    {
        $result = $this->service->getFileLines($this->tempDir . '/nonexistent.txt', 1, 10);

        self::assertStringContainsString('Error:', $result);
        self::assertStringContainsString('does not exist', $result);
    }

    #[Test]
    public function searchInFileReturnsErrorWhenFileDoesNotExist(): void
    {
        $result = $this->service->searchInFile($this->tempDir . '/nonexistent.txt', 'pattern');

        self::assertStringContainsString('Error:', $result);
        self::assertStringContainsString('does not exist', $result);
    }

    #[Test]
    public function getFileLinesReturnsSpecificLines(): void
    {
        $content = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->getFileLines($path, 2, 4);

        self::assertStringContainsString('Line 2', $result);
        self::assertStringContainsString('Line 3', $result);
        self::assertStringContainsString('Line 4', $result);
        self::assertStringNotContainsString('Line 1', $result);
        self::assertStringNotContainsString('Line 5', $result);
    }

    #[Test]
    public function getFileLinesIncludesLineNumbers(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->getFileLines($path, 1, 3);

        self::assertStringContainsString('1 |', $result);
        self::assertStringContainsString('2 |', $result);
        self::assertStringContainsString('3 |', $result);
    }

    #[Test]
    public function getFileLinesHandlesOutOfBoundsGracefully(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->getFileLines($path, 1, 100);

        self::assertStringContainsString('Line 1', $result);
        self::assertStringContainsString('Line 3', $result);
    }

    #[Test]
    public function getFileLinesReturnsMessageWhenStartLineBeyondFile(): void
    {
        $content = "Line 1\nLine 2";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->getFileLines($path, 100, 200);

        self::assertStringContainsString('only', $result);
        self::assertStringContainsString('lines', $result);
    }

    #[Test]
    public function getFileInfoReturnsCorrectMetadata(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->getFileInfo($path);

        self::assertSame($path, $result->path);
        self::assertSame(3, $result->lineCount);
        self::assertSame('txt', $result->extension);
        self::assertGreaterThan(0, $result->sizeBytes);
    }

    #[Test]
    public function getFileInfoHandlesFileWithoutExtension(): void
    {
        $content = 'Some content';
        $path    = $this->createTestFile('noextension', $content);

        $result = $this->service->getFileInfo($path);

        self::assertSame('(none)', $result->extension);
    }

    #[Test]
    public function searchInFileFindsMatches(): void
    {
        $content = "First line\nSearchable content here\nLast line";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->searchInFile($path, 'Searchable');

        self::assertStringContainsString('Found 1 match', $result);
        self::assertStringContainsString('Searchable content here', $result);
        self::assertStringContainsString('>>>', $result);
    }

    #[Test]
    public function searchInFileIncludesContext(): void
    {
        $content = "Line 1\nLine 2\nTarget line\nLine 4\nLine 5";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->searchInFile($path, 'Target', 2);

        self::assertStringContainsString('Line 2', $result);
        self::assertStringContainsString('Target line', $result);
        self::assertStringContainsString('Line 4', $result);
    }

    #[Test]
    public function searchInFileReturnsNoMatchesMessage(): void
    {
        $content = 'Some content here';
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->searchInFile($path, 'nonexistent');

        self::assertStringContainsString('No matches found', $result);
    }

    #[Test]
    public function searchInFileFindsMultipleMatches(): void
    {
        $content = "Match one\nOther line\nMatch two\nAnother line\nMatch three";
        $path    = $this->createTestFile('test.txt', $content);

        $result = $this->service->searchInFile($path, 'Match');

        self::assertStringContainsString('Found 3 match', $result);
    }

    #[Test]
    public function replaceInFileReplacesUniqueString(): void
    {
        $content = 'Hello World';
        $path    = $this->createTestFile('test.txt', $content);

        $this->service->replaceInFile($path, 'World', 'Universe');

        $newContent = file_get_contents($path);
        self::assertSame('Hello Universe', $newContent);
    }

    #[Test]
    public function replaceInFileThrowsWhenStringNotFound(): void
    {
        $content = 'Hello World';
        $path    = $this->createTestFile('test.txt', $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->service->replaceInFile($path, 'nonexistent', 'replacement');
    }

    #[Test]
    public function replaceInFileThrowsWhenMultipleOccurrences(): void
    {
        $content = 'Hello Hello World';
        $path    = $this->createTestFile('test.txt', $content);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 times');

        $this->service->replaceInFile($path, 'Hello', 'Hi');
    }

    #[Test]
    public function replaceInFileWorksWithMultilineContent(): void
    {
        $content = "Line 1\nLine 2\nLine 3";
        $path    = $this->createTestFile('test.txt', $content);

        $this->service->replaceInFile($path, "Line 2\nLine 3", "Modified 2\nModified 3");

        $newContent = file_get_contents($path);
        self::assertSame("Line 1\nModified 2\nModified 3", $newContent);
    }

    #[Test]
    public function replaceInFilePreservesWhitespace(): void
    {
        $content = '    indented line';
        $path    = $this->createTestFile('test.txt', $content);

        $this->service->replaceInFile($path, '    indented', '        double-indented');

        $newContent = file_get_contents($path);
        self::assertSame('        double-indented line', $newContent);
    }

    #[Test]
    public function createDirectoryCreatesNewDirectory(): void
    {
        $dirPath = $this->tempDir . '/new_directory';

        $result = $this->service->createDirectory($dirPath);

        self::assertDirectoryExists($dirPath);
        self::assertStringContainsString('Successfully created', $result);
    }

    #[Test]
    public function createDirectoryCreatesNestedDirectories(): void
    {
        $dirPath = $this->tempDir . '/parent/child/grandchild';

        $result = $this->service->createDirectory($dirPath);

        self::assertDirectoryExists($dirPath);
        self::assertStringContainsString('Successfully created', $result);
    }

    #[Test]
    public function createDirectoryReturnsMessageWhenDirectoryExists(): void
    {
        $dirPath = $this->tempDir . '/existing_directory';
        mkdir($dirPath, 0755, true);

        $result = $this->service->createDirectory($dirPath);

        self::assertStringContainsString('already exists', $result);
    }
}
