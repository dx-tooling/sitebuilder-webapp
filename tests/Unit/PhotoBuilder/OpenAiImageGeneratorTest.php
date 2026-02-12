<?php

declare(strict_types=1);

namespace Tests\Unit\PhotoBuilder;

use App\PhotoBuilder\Infrastructure\Adapter\OpenAiImageGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OpenAiImageGeneratorTest extends TestCase
{
    public function testReturnsDecodedImageDataOnSuccess(): void
    {
        $fakeImageData   = 'fake-png-image-bytes';
        $fakeB64         = base64_encode($fakeImageData);
        $responsePayload = json_encode(['data' => [['b64_json' => $fakeB64]]]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($responsePayload);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.openai.com/v1/images/generations',
                self::callback(static function (mixed $options): bool {
                    if (!is_array($options)) {
                        return false;
                    }

                    /** @var array<string, mixed> $json */
                    $json = $options['json'];
                    /** @var array<string, mixed> $headers */
                    $headers = $options['headers'];

                    return $json['model']         === 'gpt-image-1'
                        && $json['output_format'] === 'png'
                        && $json['prompt']        === 'A beautiful sunset'
                        && is_string($headers['Authorization'])
                        && str_contains($headers['Authorization'], 'Bearer test-key');
                })
            )
            ->willReturn($response);

        $generator = new OpenAiImageGenerator($httpClient);
        $result    = $generator->generateImage('A beautiful sunset', 'test-key', 'gpt-image-1');

        self::assertSame($fakeImageData, $result);
    }

    public function testThrowsExceptionOnNon200Status(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getContent')->willReturn('Rate limit exceeded');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $generator = new OpenAiImageGenerator($httpClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('status 429');
        $generator->generateImage('prompt', 'key', 'gpt-image-1');
    }

    public function testThrowsExceptionOnMissingDataInResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn(json_encode(['data' => []]));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $generator = new OpenAiImageGenerator($httpClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected response structure');
        $generator->generateImage('prompt', 'key', 'gpt-image-1');
    }
}
