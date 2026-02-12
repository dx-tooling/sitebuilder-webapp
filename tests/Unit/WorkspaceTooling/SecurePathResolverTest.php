<?php

declare(strict_types=1);

namespace App\Tests\Unit\WorkspaceTooling;

use App\WorkspaceTooling\Infrastructure\Security\PathTraversalException;
use App\WorkspaceTooling\Infrastructure\Security\SecurePathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurePathResolverTest extends TestCase
{
    private string $workspaceRoot;
    private string $testWorkspace;
    private SecurePathResolver $resolver;

    protected function setUp(): void
    {
        // Create a temporary workspace root for testing
        $this->workspaceRoot = sys_get_temp_dir() . '/test_workspace_root_' . uniqid();
        $this->testWorkspace = $this->workspaceRoot . '/test-workspace-id';

        mkdir($this->workspaceRoot, 0755, true);
        mkdir($this->testWorkspace, 0755, true);
        mkdir($this->testWorkspace . '/subdir', 0755, true);
        file_put_contents($this->testWorkspace . '/test-file.txt', 'test content');
        file_put_contents($this->testWorkspace . '/subdir/nested-file.txt', 'nested content');

        $this->resolver = new SecurePathResolver($this->workspaceRoot);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        $this->removeDirectory($this->workspaceRoot);
    }

    #[Test]
    public function resolveAllowsValidPathWithinWorkspace(): void
    {
        $result = $this->resolver->resolve(
            $this->testWorkspace,
            $this->testWorkspace . '/test-file.txt'
        );

        $this->assertSame(
            realpath($this->testWorkspace . '/test-file.txt'),
            $result
        );
    }

    #[Test]
    public function resolveAllowsNestedPathWithinWorkspace(): void
    {
        $result = $this->resolver->resolve(
            $this->testWorkspace,
            $this->testWorkspace . '/subdir/nested-file.txt'
        );

        $this->assertSame(
            realpath($this->testWorkspace . '/subdir/nested-file.txt'),
            $result
        );
    }

    #[Test]
    public function resolveAllowsRelativePath(): void
    {
        $result = $this->resolver->resolve(
            $this->testWorkspace,
            'test-file.txt'
        );

        $this->assertSame(
            realpath($this->testWorkspace . '/test-file.txt'),
            $result
        );
    }

    #[Test]
    public function resolveBlocksPathTraversalWithDotDot(): void
    {
        $this->expectException(PathTraversalException::class);

        $this->resolver->resolve(
            $this->testWorkspace,
            $this->testWorkspace . '/../../../etc/passwd'
        );
    }

    #[Test]
    public function resolveBlocksRelativePathTraversal(): void
    {
        $this->expectException(PathTraversalException::class);

        $this->resolver->resolve(
            $this->testWorkspace,
            '../../../etc/passwd'
        );
    }

    #[Test]
    public function resolveBlocksAbsolutePathOutsideWorkspace(): void
    {
        $this->expectException(PathTraversalException::class);

        $this->resolver->resolve(
            $this->testWorkspace,
            '/etc/passwd'
        );
    }

    #[Test]
    public function resolveBlocksWorkspaceOutsideRoot(): void
    {
        $this->expectException(PathTraversalException::class);

        $this->resolver->resolve(
            '/tmp',
            '/tmp/some-file.txt'
        );
    }

    #[Test]
    public function isWithinWorkspaceRootReturnsTrueForValidPath(): void
    {
        $result = $this->resolver->isWithinWorkspaceRoot($this->testWorkspace);

        $this->assertTrue($result);
    }

    #[Test]
    public function isWithinWorkspaceRootReturnsTrueForFileInWorkspace(): void
    {
        $result = $this->resolver->isWithinWorkspaceRoot(
            $this->testWorkspace . '/test-file.txt'
        );

        $this->assertTrue($result);
    }

    #[Test]
    public function isWithinWorkspaceRootReturnsFalseForPathOutsideRoot(): void
    {
        $result = $this->resolver->isWithinWorkspaceRoot('/etc/passwd');

        $this->assertFalse($result);
    }

    #[Test]
    public function isWithinWorkspaceRootReturnsFalseForParentDirectory(): void
    {
        $result = $this->resolver->isWithinWorkspaceRoot($this->workspaceRoot . '/..');

        $this->assertFalse($result);
    }

    #[Test]
    public function extractWorkspaceIdReturnsDirectoryName(): void
    {
        $result = $this->resolver->extractWorkspaceId($this->testWorkspace);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('test-workspace-id', $result);
    }

    #[Test]
    public function extractWorkspaceIdReturnsNullForPathOutsideRoot(): void
    {
        $result = $this->resolver->extractWorkspaceId('/etc');

        $this->assertNull($result);
    }

    #[Test]
    public function resolveHandlesPathWithDotInMiddle(): void
    {
        $result = $this->resolver->resolve(
            $this->testWorkspace,
            $this->testWorkspace . '/./test-file.txt'
        );

        $this->assertSame(
            realpath($this->testWorkspace . '/test-file.txt'),
            $result
        );
    }

    #[Test]
    public function resolveHandlesNonExistentFileInValidDirectory(): void
    {
        $result = $this->resolver->resolve(
            $this->testWorkspace,
            $this->testWorkspace . '/new-file.txt'
        );

        $expectedPath = realpath($this->testWorkspace) . '/new-file.txt';
        $this->assertSame($expectedPath, $result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
