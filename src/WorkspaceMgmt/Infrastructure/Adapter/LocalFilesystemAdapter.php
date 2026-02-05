<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Local filesystem adapter using Symfony Filesystem component.
 */
final class LocalFilesystemAdapter implements FilesystemAdapterInterface
{
    private readonly Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function removeDirectory(string $path): void
    {
        if ($this->filesystem->exists($path)) {
            $this->filesystem->remove($path);
        }
    }

    public function createDirectory(string $path): void
    {
        $this->filesystem->mkdir($path);
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    public function readFile(string $path): string
    {
        if (!$this->filesystem->exists($path)) {
            throw new RuntimeException('File does not exist: ' . $path);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Failed to read file: ' . $path);
        }

        return $content;
    }

    public function writeFile(string $path, string $content): void
    {
        $this->filesystem->dumpFile($path, $content);
    }
}
