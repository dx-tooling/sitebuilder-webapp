<?php

declare(strict_types=1);

use App\PhotoBuilder\Infrastructure\Storage\GeneratedImageStorage;

function cleanupTestDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}

/**
 * @return array{string, GeneratedImageStorage}
 */
function createStorageFixture(): array
{
    $baseDir = sys_get_temp_dir() . '/photo-builder-test-' . uniqid();

    return [$baseDir, new GeneratedImageStorage($baseDir)];
}

describe('GeneratedImageStorage', function (): void {
    describe('save', function (): void {
        it('saves image data and returns relative path', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $imageData   = 'fake-png-data';
                $storagePath = $storage->save('session-123', 0, $imageData);

                expect($storagePath)->toBe('session-123/0.png');

                $absolutePath = $baseDir . '/' . $storagePath;
                expect(file_exists($absolutePath))->toBeTrue()
                    ->and(file_get_contents($absolutePath))->toBe('fake-png-data');
            } finally {
                cleanupTestDir($baseDir);
            }
        });

        it('creates directory structure if not exists', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $storage->save('new-session', 3, 'data');

                $dir = $baseDir . '/new-session';
                expect(is_dir($dir))->toBeTrue();
            } finally {
                cleanupTestDir($baseDir);
            }
        });

        it('handles multiple positions in same session', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $storage->save('session-abc', 0, 'image-0');
                $storage->save('session-abc', 1, 'image-1');
                $storage->save('session-abc', 4, 'image-4');

                expect(file_get_contents($baseDir . '/session-abc/0.png'))->toBe('image-0')
                    ->and(file_get_contents($baseDir . '/session-abc/1.png'))->toBe('image-1')
                    ->and(file_get_contents($baseDir . '/session-abc/4.png'))->toBe('image-4');
            } finally {
                cleanupTestDir($baseDir);
            }
        });
    });

    describe('read', function (): void {
        it('reads saved image data', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $storage->save('session-123', 0, 'my-image-data');

                $data = $storage->read('session-123/0.png');
                expect($data)->toBe('my-image-data');
            } finally {
                cleanupTestDir($baseDir);
            }
        });

        it('throws exception for non-existent file', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                expect(fn () => $storage->read('nonexistent/0.png'))
                    ->toThrow(RuntimeException::class);
            } finally {
                cleanupTestDir($baseDir);
            }
        });
    });

    describe('getAbsolutePath', function (): void {
        it('returns absolute path for a relative storage path', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $absolute = $storage->getAbsolutePath('session-123/0.png');
                expect($absolute)->toBe($baseDir . '/session-123/0.png');
            } finally {
                cleanupTestDir($baseDir);
            }
        });
    });

    describe('exists', function (): void {
        it('returns true for existing file', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                $storage->save('session-123', 0, 'data');
                expect($storage->exists('session-123/0.png'))->toBeTrue();
            } finally {
                cleanupTestDir($baseDir);
            }
        });

        it('returns false for non-existing file', function (): void {
            [$baseDir, $storage] = createStorageFixture();

            try {
                expect($storage->exists('nonexistent/0.png'))->toBeFalse();
            } finally {
                cleanupTestDir($baseDir);
            }
        });
    });
});
