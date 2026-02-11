<?php

declare(strict_types=1);

namespace Tests\Unit\PhotoBuilder;

use App\PhotoBuilder\Infrastructure\Adapter\GeminiImageGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GeminiImageGeneratorTest extends TestCase
{
    public function testReturnsDecodedImageDataOnSuccess(): void
    {
        $fakeImageData   = 'fake-png-image-bytes';
        $fakeB64         = base64_encode($fakeImageData);
        $responsePayload = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['inlineData' => ['data' => $fakeB64, 'mimeType' => 'image/png']],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($responsePayload);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $generator = new GeminiImageGenerator($httpClient);
        $result    = $generator->generateImage('A beautiful sunset', 'test-key');

        self::assertSame($fakeImageData, $result);
    }

    public function testPassesImageSizeInGenerationConfig(): void
    {
        $fakeImageData   = 'fake-png-image-bytes';
        $fakeB64         = base64_encode($fakeImageData);
        $responsePayload = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['inlineData' => ['data' => $fakeB64, 'mimeType' => 'image/png']],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($responsePayload);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::stringContains('gemini-3-pro-image-preview'),
                self::callback(static function (mixed $options): bool {
                    if (!is_array($options)) {
                        return false;
                    }

                    /** @var array{json: array{generationConfig: array{imageConfig?: array{imageSize?: string}}}} $opts */
                    $opts             = $options;
                    $generationConfig = $opts['json']['generationConfig'];

                    return array_key_exists('imageConfig', $generationConfig)
                        && ($generationConfig['imageConfig']['imageSize'] ?? null) === '1K';
                })
            )
            ->willReturn($response);

        $generator = new GeminiImageGenerator($httpClient);
        $result    = $generator->generateImage('A beautiful sunset', 'test-key', '1K');

        self::assertSame($fakeImageData, $result);
    }

    public function testOmitsImageConfigWhenImageSizeIsNull(): void
    {
        $fakeImageData   = 'fake-png-image-bytes';
        $fakeB64         = base64_encode($fakeImageData);
        $responsePayload = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['inlineData' => ['data' => $fakeB64, 'mimeType' => 'image/png']],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($responsePayload);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::stringContains('gemini-3-pro-image-preview'),
                self::callback(static function (mixed $options): bool {
                    if (!is_array($options)) {
                        return false;
                    }

                    /** @var array{json: array{generationConfig: array<string, mixed>}} $opts */
                    $opts             = $options;
                    $generationConfig = $opts['json']['generationConfig'];

                    return !array_key_exists('imageConfig', $generationConfig);
                })
            )
            ->willReturn($response);

        $generator = new GeminiImageGenerator($httpClient);
        $generator->generateImage('A sunset', 'test-key', null);
    }

    public function testThrowsExceptionOnNon200Status(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getContent')->willReturn('Rate limit exceeded');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $generator = new GeminiImageGenerator($httpClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status 429');
        $generator->generateImage('prompt', 'key');
    }

    public function testThrowsExceptionOnMissingImageData(): void
    {
        $responsePayload = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'No image here'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($responsePayload);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $generator = new GeminiImageGenerator($httpClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no image data');
        $generator->generateImage('prompt', 'key');
    }
}
