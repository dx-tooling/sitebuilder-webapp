<?php

declare(strict_types=1);

use App\PhotoBuilder\Infrastructure\Adapter\OpenAiImageGenerator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

describe('OpenAiImageGenerator', function (): void {
    describe('generateImage', function (): void {
        it('returns decoded image data on success', function (): void {
            $fakeImageData   = 'fake-png-image-bytes';
            $fakeB64         = base64_encode($fakeImageData);
            $responsePayload = json_encode(['data' => [['b64_json' => $fakeB64]]]);

            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(200);
            $response->method('getContent')->willReturn($responsePayload);

            $httpClient = $this->createMock(HttpClientInterface::class);
            $httpClient->expects($this->once())
                ->method('request')
                ->with(
                    'POST',
                    'https://api.openai.com/v1/images/generations',
                    $this->callback(function (array $options) {
                        return $options['json']['model'] === 'gpt-image-1'
                            && $options['json']['response_format'] === 'b64_json'
                            && $options['json']['prompt'] === 'A beautiful sunset'
                            && str_contains($options['headers']['Authorization'], 'Bearer test-key');
                    })
                )
                ->willReturn($response);

            $generator = new OpenAiImageGenerator($httpClient);
            $result    = $generator->generateImage('A beautiful sunset', 'test-key');

            expect($result)->toBe($fakeImageData);
        });

        it('throws exception on non-200 status', function (): void {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(429);
            $response->method('getContent')->willReturn('Rate limit exceeded');

            $httpClient = $this->createMock(HttpClientInterface::class);
            $httpClient->method('request')->willReturn($response);

            $generator = new OpenAiImageGenerator($httpClient);

            expect(fn () => $generator->generateImage('prompt', 'key'))
                ->toThrow(RuntimeException::class, 'status 429');
        });

        it('throws exception on missing data in response', function (): void {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(200);
            $response->method('getContent')->willReturn(json_encode(['data' => []]));

            $httpClient = $this->createMock(HttpClientInterface::class);
            $httpClient->method('request')->willReturn($response);

            $generator = new OpenAiImageGenerator($httpClient);

            expect(fn () => $generator->generateImage('prompt', 'key'))
                ->toThrow(RuntimeException::class, 'unexpected response structure');
        });
    });
});
