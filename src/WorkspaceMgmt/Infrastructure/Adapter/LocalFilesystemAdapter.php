<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

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
}
