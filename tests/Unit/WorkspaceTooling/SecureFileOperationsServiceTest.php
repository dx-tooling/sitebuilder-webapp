<?php

declare(strict_types=1);

namespace Tests\Unit\WorkspaceTooling;

use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use App\WorkspaceTooling\Infrastructure\Security\SecureFileOperationsService;
use App\WorkspaceTooling\Infrastructure\Security\SecurePathResolverInterface;
use EtfsCodingAgent\Service\Dto\FileInfoDto;
use EtfsCodingAgent\Service\FileOperationsServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecureFileOperationsService path translation in results.
 *
 * Verifies that tool results display /workspace paths (as the agent sees them)
 * instead of internal filesystem paths like /var/www/public/workspaces/{uuid}/...
 */
final class SecureFileOperationsServiceTest extends TestCase
{
    private const string WORKSPACE_ROOT  = '/var/www/public/workspaces';
    private const string WORKSPACE_ID    = '019c0920-bba7-725c-adbb-ba7fe46e15de';
    private const string ACTUAL_PATH     = self::WORKSPACE_ROOT . '/' . self::WORKSPACE_ID;
    private const string WORKSPACE_ALIAS = '/workspace';

    private FileOperationsServiceInterface&MockObject $innerMock;
    private SecurePathResolverInterface&MockObject $pathResolverMock;
    private AgentExecutionContext $executionContext;
    private SecureFileOperationsService $service;

    protected function setUp(): void
    {
        $this->innerMock        = $this->createMock(FileOperationsServiceInterface::class);
        $this->pathResolverMock = $this->createMock(SecurePathResolverInterface::class);
        $this->executionContext = new AgentExecutionContext();

        // Set up execution context with workspace path
        $this->executionContext->setContext(
            self::WORKSPACE_ID,
            self::ACTUAL_PATH,
            'conversation-id',
            'project-name',
            'agent-image',
            null
        );

        // Path resolver always returns true (valid path)
        $this->pathResolverMock->method('isWithinWorkspaceRoot')->willReturn(true);

        $this->service = new SecureFileOperationsService(
            $this->innerMock,
            $this->pathResolverMock,
            $this->executionContext,
            self::WORKSPACE_ROOT
        );
    }

    #[Test]
    public function replaceInFileTranslatesPathInSuccessMessage(): void
    {
        $this->innerMock
            ->method('replaceInFile')
            ->willReturn('Successfully replaced string in ' . self::ACTUAL_PATH . '/src/index.html');

        $result = $this->service->replaceInFile(
            self::WORKSPACE_ALIAS . '/src/index.html',
            'old',
            'new'
        );

        $this->assertSame(
            'Successfully replaced string in /workspace/src/index.html',
            $result
        );
    }

    #[Test]
    public function createDirectoryTranslatesPathInSuccessMessage(): void
    {
        $this->innerMock
            ->method('createDirectory')
            ->willReturn('Successfully created directory: ' . self::ACTUAL_PATH . '/new-folder');

        $result = $this->service->createDirectory(self::WORKSPACE_ALIAS . '/new-folder');

        $this->assertSame(
            'Successfully created directory: /workspace/new-folder',
            $result
        );
    }

    #[Test]
    public function createDirectoryTranslatesPathInAlreadyExistsMessage(): void
    {
        $this->innerMock
            ->method('createDirectory')
            ->willReturn('Directory already exists: ' . self::ACTUAL_PATH . '/existing-folder');

        $result = $this->service->createDirectory(self::WORKSPACE_ALIAS . '/existing-folder');

        $this->assertSame(
            'Directory already exists: /workspace/existing-folder',
            $result
        );
    }

    #[Test]
    public function listFolderContentTranslatesPathInErrorMessage(): void
    {
        $this->innerMock
            ->method('listFolderContent')
            ->willReturn('Error: Directory does not exist: ' . self::ACTUAL_PATH . '/missing-dir. Use create_directory to create it first.');

        $result = $this->service->listFolderContent(self::WORKSPACE_ALIAS . '/missing-dir');

        $this->assertSame(
            'Error: Directory does not exist: /workspace/missing-dir. Use create_directory to create it first.',
            $result
        );
    }

    #[Test]
    public function getFileContentTranslatesPathInErrorMessage(): void
    {
        $this->innerMock
            ->method('getFileContent')
            ->willReturn('Error: File does not exist: ' . self::ACTUAL_PATH . '/missing.txt');

        $result = $this->service->getFileContent(self::WORKSPACE_ALIAS . '/missing.txt');

        $this->assertSame(
            'Error: File does not exist: /workspace/missing.txt',
            $result
        );
    }

    #[Test]
    public function getFileContentReturnsContentUnchangedWhenNoPath(): void
    {
        $fileContent = "<?php\necho 'Hello World';\n";
        $this->innerMock
            ->method('getFileContent')
            ->willReturn($fileContent);

        $result = $this->service->getFileContent(self::WORKSPACE_ALIAS . '/test.php');

        $this->assertSame($fileContent, $result);
    }

    #[Test]
    public function getFileLinesTranslatesPathInErrorMessage(): void
    {
        $this->innerMock
            ->method('getFileLines')
            ->willReturn('Error: File does not exist: ' . self::ACTUAL_PATH . '/missing.txt');

        $result = $this->service->getFileLines(self::WORKSPACE_ALIAS . '/missing.txt', 1, 10);

        $this->assertSame(
            'Error: File does not exist: /workspace/missing.txt',
            $result
        );
    }

    #[Test]
    public function searchInFileTranslatesPathInErrorMessage(): void
    {
        $this->innerMock
            ->method('searchInFile')
            ->willReturn('Error: File does not exist: ' . self::ACTUAL_PATH . '/missing.txt');

        $result = $this->service->searchInFile(self::WORKSPACE_ALIAS . '/missing.txt', 'pattern');

        $this->assertSame(
            'Error: File does not exist: /workspace/missing.txt',
            $result
        );
    }

    #[Test]
    public function getFileInfoTranslatesPathInDto(): void
    {
        $innerDto = new FileInfoDto(
            self::ACTUAL_PATH . '/src/index.html',
            100,
            2048,
            'html'
        );

        $this->innerMock
            ->method('getFileInfo')
            ->willReturn($innerDto);

        $result = $this->service->getFileInfo(self::WORKSPACE_ALIAS . '/src/index.html');

        $this->assertSame('/workspace/src/index.html', $result->path);
        $this->assertSame(100, $result->lineCount);
        $this->assertSame(2048, $result->sizeBytes);
        $this->assertSame('html', $result->extension);
    }

    #[Test]
    public function pathTranslationHandlesMultipleOccurrencesInMessage(): void
    {
        // Some error messages might reference the path multiple times
        $this->innerMock
            ->method('listFolderContent')
            ->willReturn('Path ' . self::ACTUAL_PATH . '/foo is invalid, expected ' . self::ACTUAL_PATH . '/bar');

        $result = $this->service->listFolderContent(self::WORKSPACE_ALIAS . '/foo');

        $this->assertSame(
            'Path /workspace/foo is invalid, expected /workspace/bar',
            $result
        );
    }

    #[Test]
    public function pathTranslationWorksWithoutContextSet(): void
    {
        // Create service with fresh execution context (no context set)
        $freshContext = new AgentExecutionContext();
        $service      = new SecureFileOperationsService(
            $this->innerMock,
            $this->pathResolverMock,
            $freshContext,
            self::WORKSPACE_ROOT
        );

        $this->innerMock
            ->method('createDirectory')
            ->willReturn('Successfully created directory: /some/path');

        $result = $service->createDirectory('/some/path');

        // Without context, path should pass through unchanged
        $this->assertSame('Successfully created directory: /some/path', $result);
    }
}
