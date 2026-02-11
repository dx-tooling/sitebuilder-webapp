<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Storage;

use RuntimeException;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Filesystem adapter for storing generated images.
 *
 * Images are stored on disk at: {baseDir}/{sessionId}/{position}.png
 */
class GeneratedImageStorage
{
    public function __construct(
        private readonly string $baseDir,
    ) {
    }

    /**
     * Save image data to disk.
     *
     * @return string Relative storage path (e.g. "session-123/0.png")
     */
    public function save(string $sessionId, int $position, string $imageData): string
    {
        $relativePath = sprintf('%s/%d.png', $sessionId, $position);
        $absolutePath = $this->baseDir . '/' . $relativePath;

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($absolutePath, $imageData);

        return $relativePath;
    }

    /**
     * Read image data from disk.
     *
     * @throws RuntimeException If the file does not exist
     */
    public function read(string $storagePath): string
    {
        $absolutePath = $this->getAbsolutePath($storagePath);

        if (!file_exists($absolutePath)) {
            throw new RuntimeException(sprintf('Generated image not found: %s', $storagePath));
        }

        $data = file_get_contents($absolutePath);

        if ($data === false) {
            throw new RuntimeException(sprintf('Failed to read generated image: %s', $storagePath));
        }

        return $data;
    }

    /**
     * Get the absolute filesystem path for a relative storage path.
     */
    public function getAbsolutePath(string $storagePath): string
    {
        return $this->baseDir . '/' . $storagePath;
    }

    /**
     * Check whether a stored image file exists.
     */
    public function exists(string $storagePath): bool
    {
        return file_exists($this->getAbsolutePath($storagePath));
    }
}
