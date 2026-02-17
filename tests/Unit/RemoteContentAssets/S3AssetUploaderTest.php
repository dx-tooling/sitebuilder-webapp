<?php

declare(strict_types=1);

namespace App\Tests\Unit\RemoteContentAssets;

use App\RemoteContentAssets\Infrastructure\S3AssetUploader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for S3AssetUploader: filename sanitization and key prefix handling.
 */
final class S3AssetUploaderTest extends TestCase
{
    private S3AssetUploader $uploader;
    private ReflectionMethod $generateUniqueKey;

    protected function setUp(): void
    {
        $logger         = $this->createMock(LoggerInterface::class);
        $this->uploader = new S3AssetUploader($logger);

        $this->generateUniqueKey = new ReflectionMethod(S3AssetUploader::class, 'generateUniqueKey');
    }

    // ==========================================
    // Filename Sanitization
    // ==========================================

    public function testSanitizesSpecialCharactersInFilename(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'hello world (copy).jpg');

        // Special characters should be replaced with underscores
        self::assertStringContainsString('hello_world__copy_', $key);
        self::assertStringEndsWith('.jpg', $key);
    }

    public function testSanitizesUmlautsAndUnicodeCharacters(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'über-straße.png');

        // Multi-byte characters (ü = 2 bytes, ß = 2 bytes) each become underscores
        self::assertStringContainsString('__ber-stra__e', $key);
        self::assertStringEndsWith('.png', $key);
    }

    public function testKeepsAlphanumericDashAndUnderscore(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'my-image_2024.webp');

        self::assertStringContainsString('my-image_2024', $key);
        self::assertStringEndsWith('.webp', $key);
    }

    public function testTruncatesLongFilenameToFiftyCharacters(): void
    {
        $longName = str_repeat('a', 100) . '.jpg';
        $key      = $this->callGenerateUniqueKey(null, $longName);

        // Extract the basename part (after hex_)
        preg_match('/[a-f0-9]{16}_(.+)\.jpg$/', $key, $matches);
        self::assertNotEmpty($matches, 'Key should match expected format');
        self::assertSame(50, mb_strlen($matches[1]));
    }

    public function testHandlesFilenameWithoutExtension(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'README');

        // Should not end with a dot
        self::assertDoesNotMatchRegularExpression('/\.$/', $key);
        self::assertMatchesRegularExpression('/[a-f0-9]{16}_README$/', $key);
    }

    public function testPreservesFileExtension(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'photo.avif');

        self::assertStringEndsWith('.avif', $key);
    }

    // ==========================================
    // Key Prefix Handling
    // ==========================================

    public function testKeyWithoutPrefixStartsWithUploads(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'image.jpg');

        self::assertMatchesRegularExpression('#^uploads/\d{8}/[a-f0-9]{16}_image\.jpg$#', $key);
    }

    public function testKeyWithPrefixPrependsPrefix(): void
    {
        $key = $this->callGenerateUniqueKey('my-project', 'image.jpg');

        self::assertMatchesRegularExpression('#^my-project/uploads/\d{8}/[a-f0-9]{16}_image\.jpg$#', $key);
    }

    public function testKeyWithTrailingSlashPrefixDoesNotDoubleSlash(): void
    {
        $key = $this->callGenerateUniqueKey('my-project/', 'image.jpg');

        self::assertStringStartsWith('my-project/uploads/', $key);
        self::assertStringNotContainsString('//', $key);
    }

    public function testEmptyStringPrefixIsTreatedAsNoPrefix(): void
    {
        $key = $this->callGenerateUniqueKey('', 'image.jpg');

        self::assertMatchesRegularExpression('#^uploads/\d{8}/#', $key);
    }

    // ==========================================
    // Key Format
    // ==========================================

    public function testKeyContainsDateFolder(): void
    {
        $key          = $this->callGenerateUniqueKey(null, 'test.png');
        $expectedDate = date('Ymd');

        self::assertStringContainsString('uploads/' . $expectedDate . '/', $key);
    }

    public function testKeyContainsSixteenCharHexPrefix(): void
    {
        $key = $this->callGenerateUniqueKey(null, 'test.png');

        self::assertMatchesRegularExpression('#/[a-f0-9]{16}_#', $key);
    }

    // ==========================================
    // Helper
    // ==========================================

    private function callGenerateUniqueKey(?string $keyPrefix, string $filename): string
    {
        /** @var string $result */
        $result = $this->generateUniqueKey->invoke($this->uploader, $keyPrefix, $filename);

        return $result;
    }
}
