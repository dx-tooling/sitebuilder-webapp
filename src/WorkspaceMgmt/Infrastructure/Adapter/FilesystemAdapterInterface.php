<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

/**
 * Interface for filesystem operations.
 */
interface FilesystemAdapterInterface
{
    /**
     * Remove a directory and all its contents recursively.
     *
     * @param string $path the directory path to remove
     */
    public function removeDirectory(string $path): void;

    /**
     * Create a directory.
     *
     * @param string $path the directory path to create
     */
    public function createDirectory(string $path): void;

    /**
     * Check if a path exists.
     *
     * @param string $path the path to check
     *
     * @return bool true if exists, false otherwise
     */
    public function exists(string $path): bool;

    /**
     * Read the contents of a file.
     *
     * @param string $path the file path to read
     *
     * @return string the file contents
     */
    public function readFile(string $path): string;

    /**
     * Write contents to a file.
     *
     * @param string $path    the file path to write to
     * @param string $content the content to write
     */
    public function writeFile(string $path, string $content): void;
}
