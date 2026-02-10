<?php

declare(strict_types=1);

use App\PhotoBuilder\Infrastructure\Storage\GeneratedImageStorage;

describe('GeneratedImageStorage', function (): void {
    beforeEach(function (): void {
        $this->baseDir = sys_get_temp_dir() . '/photo-builder-test-' . uniqid();
        $this->storage = new GeneratedImageStorage($this->baseDir);
    });

    afterEach(function (): void {
        // Clean up temp directory
        if (is_dir($this->baseDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($this->baseDir);
        }
    });

    describe('save', function (): void {
        it('saves image data and returns relative path', function (): void {
            $imageData   = 'fake-png-data';
            $storagePath = $this->storage->save('session-123', 0, $imageData);

            expect($storagePath)->toBe('session-123/0.png');

            $absolutePath = $this->baseDir . '/' . $storagePath;
            expect(file_exists($absolutePath))->toBeTrue()
                ->and(file_get_contents($absolutePath))->toBe('fake-png-data');
        });

        it('creates directory structure if not exists', function (): void {
            $this->storage->save('new-session', 3, 'data');

            $dir = $this->baseDir . '/new-session';
            expect(is_dir($dir))->toBeTrue();
        });

        it('handles multiple positions in same session', function (): void {
            $this->storage->save('session-abc', 0, 'image-0');
            $this->storage->save('session-abc', 1, 'image-1');
            $this->storage->save('session-abc', 4, 'image-4');

            expect(file_get_contents($this->baseDir . '/session-abc/0.png'))->toBe('image-0')
                ->and(file_get_contents($this->baseDir . '/session-abc/1.png'))->toBe('image-1')
                ->and(file_get_contents($this->baseDir . '/session-abc/4.png'))->toBe('image-4');
        });
    });

    describe('read', function (): void {
        it('reads saved image data', function (): void {
            $this->storage->save('session-123', 0, 'my-image-data');

            $data = $this->storage->read('session-123/0.png');
            expect($data)->toBe('my-image-data');
        });

        it('throws exception for non-existent file', function (): void {
            expect(fn () => $this->storage->read('nonexistent/0.png'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('getAbsolutePath', function (): void {
        it('returns absolute path for a relative storage path', function (): void {
            $absolute = $this->storage->getAbsolutePath('session-123/0.png');
            expect($absolute)->toBe($this->baseDir . '/session-123/0.png');
        });
    });

    describe('exists', function (): void {
        it('returns true for existing file', function (): void {
            $this->storage->save('session-123', 0, 'data');
            expect($this->storage->exists('session-123/0.png'))->toBeTrue();
        });

        it('returns false for non-existing file', function (): void {
            expect($this->storage->exists('nonexistent/0.png'))->toBeFalse();
        });
    });
});
