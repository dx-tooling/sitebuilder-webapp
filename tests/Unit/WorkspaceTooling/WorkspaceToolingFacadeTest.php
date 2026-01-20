<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceTooling;

use App\WorkspaceTooling\Facade\WorkspaceToolingFacade;
use EtfsCodingAgent\Service\FileOperationsService;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use PHPUnit\Framework\TestCase;

final class WorkspaceToolingFacadeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/wstest_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testApplyV4aDiffToFileReturnsShortSummaryWithCorrectLineCount(): void
    {
        $path = $this->tempDir . '/f.txt';
        file_put_contents($path, "line 1\nline 2\nline 3");
        $diff = " line 1\n-line 2\n+line 2 updated\n line 3";

        $facade = $this->createFacade();
        $result = $facade->applyV4aDiffToFile($path, $diff);

        self::assertSame('Applied. File now has 3 lines.', $result);
    }

    public function testApplyV4aDiffToFileWritesModifiedContentToFile(): void
    {
        $path = $this->tempDir . '/f.txt';
        file_put_contents($path, "line 1\nline 2\nline 3");
        $diff = " line 1\n-line 2\n+line 2 updated\n line 3";

        $facade = $this->createFacade();
        $facade->applyV4aDiffToFile($path, $diff);

        self::assertSame("line 1\nline 2 updated\nline 3", file_get_contents($path));
    }

    public function testApplyV4aDiffToFileWithSingleLineContentReturnsOneLine(): void
    {
        $path = $this->tempDir . '/one.txt';
        file_put_contents($path, 'single line');
        $diff = ' single line';

        $facade = $this->createFacade();
        $result = $facade->applyV4aDiffToFile($path, $diff);

        self::assertSame('Applied. File now has 1 lines.', $result);
    }

    private function createFacade(): WorkspaceToolingFacade
    {
        $fileOps  = new FileOperationsService();
        $textOps  = new TextOperationsService($fileOps);
        $shellOps = $this->createMock(ShellOperationsServiceInterface::class);

        return new WorkspaceToolingFacade($fileOps, $textOps, $shellOps);
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
}
