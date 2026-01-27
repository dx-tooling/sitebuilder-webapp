<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceTooling;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacadeInterface;
use App\WorkspaceTooling\Facade\WorkspaceToolingFacade;
use App\WorkspaceTooling\Infrastructure\Execution\AgentExecutionContext;
use EtfsCodingAgent\Service\FileOperationsService;
use EtfsCodingAgent\Service\ShellOperationsServiceInterface;
use EtfsCodingAgent\Service\TextOperationsService;
use PHPUnit\Framework\TestCase;

final class WorkspaceToolingFacadeTest extends TestCase
{
    private string $tempDir;
    private AgentExecutionContext $executionContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir          = sys_get_temp_dir() . '/wstest_' . uniqid();
        $this->executionContext = new AgentExecutionContext();
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

    public function testSuggestCommitMessageStoresMessageInContext(): void
    {
        $facade = $this->createFacade();

        $facade->suggestCommitMessage('Add hero section to homepage');

        self::assertSame('Add hero section to homepage', $this->executionContext->getSuggestedCommitMessage());
    }

    public function testSuggestCommitMessageReturnsConfirmation(): void
    {
        $facade = $this->createFacade();

        $result = $facade->suggestCommitMessage('Fix broken navigation links');

        self::assertSame('Commit message recorded: Fix broken navigation links', $result);
    }

    public function testSuggestCommitMessageOverwritesPreviousMessage(): void
    {
        $facade = $this->createFacade();

        $facade->suggestCommitMessage('First message');
        $facade->suggestCommitMessage('Second message');

        self::assertSame('Second message', $this->executionContext->getSuggestedCommitMessage());
    }

    public function testGetPreviewUrlReturnsCorrectUrlForDistFile(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('/workspace/dist/handwerk.html');

        self::assertSame(
            '/workspaces/019bf530-efa6-77a6-b076-2923c7a579d2/dist/handwerk.html',
            $result
        );
    }

    public function testGetPreviewUrlHandlesNestedPaths(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('/workspace/dist/pages/about/index.html');

        self::assertSame(
            '/workspaces/019bf530-efa6-77a6-b076-2923c7a579d2/dist/pages/about/index.html',
            $result
        );
    }

    public function testGetPreviewUrlHandlesNonDistPaths(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('/workspace/src/index.html');

        self::assertSame(
            '/workspaces/019bf530-efa6-77a6-b076-2923c7a579d2/src/index.html',
            $result
        );
    }

    public function testGetPreviewUrlReturnsErrorWhenContextNotSet(): void
    {
        $facade = $this->createFacade();
        // Context NOT set

        $result = $facade->getPreviewUrl('/workspace/dist/foo.html');

        self::assertStringContainsString('Error', $result);
        self::assertStringContainsString('context not set', strtolower($result));
    }

    public function testGetPreviewUrlReturnsErrorForPathTraversal(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('/workspace/../../../etc/passwd');

        self::assertStringContainsString('Error', $result);
        self::assertStringContainsString('path traversal', strtolower($result));
    }

    public function testGetPreviewUrlHandlesPathWithoutWorkspacePrefix(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('dist/foo.html');

        self::assertSame(
            '/workspaces/019bf530-efa6-77a6-b076-2923c7a579d2/dist/foo.html',
            $result
        );
    }

    public function testGetPreviewUrlNormalizesDoubleSlashes(): void
    {
        $this->executionContext->setContext(
            '019bf530-efa6-77a6-b076-2923c7a579d2',
            '/path/to/workspace',
            'conv-id',
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->getPreviewUrl('/workspace//dist//foo.html');

        self::assertSame(
            '/workspaces/019bf530-efa6-77a6-b076-2923c7a579d2/dist/foo.html',
            $result
        );
    }

    public function testListRemoteContentAssetUrlsReturnsEmptyArrayWhenNoManifestUrlsConfigured(): void
    {
        $this->executionContext->setContext(
            'workspace-id',
            '/path',
            null,
            'project',
            'image'
        );
        $facade = $this->createFacade();

        $result = $facade->listRemoteContentAssetUrls();

        self::assertSame('[]', $result);
    }

    private function createFacade(): WorkspaceToolingFacade
    {
        $fileOps                   = new FileOperationsService();
        $textOps                   = new TextOperationsService($fileOps);
        $shellOps                  = $this->createMock(ShellOperationsServiceInterface::class);
        $remoteContentAssetsFacade = $this->createMock(RemoteContentAssetsFacadeInterface::class);

        return new WorkspaceToolingFacade($fileOps, $textOps, $shellOps, $this->executionContext, $remoteContentAssetsFacade);
    }

    public function testGetRemoteAssetInfoReturnsErrorJsonWhenFacadeReturnsNull(): void
    {
        $remoteContentAssets = $this->createMock(RemoteContentAssetsFacadeInterface::class);
        $remoteContentAssets->method('getRemoteAssetInfo')->willReturn(null);
        $facade = $this->createFacadeWithRemoteContentAssets($remoteContentAssets);

        $result = $facade->getRemoteAssetInfo('https://example.com/image.png');

        self::assertSame('{"error":"Could not retrieve asset info"}', $result);
    }

    public function testGetRemoteAssetInfoReturnsJsonWhenFacadeReturnsDto(): void
    {
        $dto = new RemoteContentAssetInfoDto(
            'https://example.com/cat.jpg',
            800,
            600,
            'image/jpeg',
            45_000
        );
        $remoteContentAssets = $this->createMock(RemoteContentAssetsFacadeInterface::class);
        $remoteContentAssets->method('getRemoteAssetInfo')->willReturn($dto);
        $facade = $this->createFacadeWithRemoteContentAssets($remoteContentAssets);

        $result = $facade->getRemoteAssetInfo('https://example.com/cat.jpg');

        $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('url', $decoded);
        self::assertSame('https://example.com/cat.jpg', $decoded['url']);
        self::assertSame(800, $decoded['width']);
        self::assertSame(600, $decoded['height']);
        self::assertSame('image/jpeg', $decoded['mimeType']);
        self::assertSame(45_000, $decoded['sizeInBytes']);
    }

    private function createFacadeWithRemoteContentAssets(RemoteContentAssetsFacadeInterface $remoteContentAssetsFacade): WorkspaceToolingFacade
    {
        $fileOps  = new FileOperationsService();
        $textOps  = new TextOperationsService($fileOps);
        $shellOps = $this->createMock(ShellOperationsServiceInterface::class);

        return new WorkspaceToolingFacade($fileOps, $textOps, $shellOps, $this->executionContext, $remoteContentAssetsFacade);
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
