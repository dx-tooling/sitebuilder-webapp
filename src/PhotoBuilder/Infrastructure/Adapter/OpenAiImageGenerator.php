<?php

declare(strict_types=1);

namespace App\PhotoBuilder\Infrastructure\Adapter;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function base64_decode;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Generates images using the OpenAI Images API (gpt-image-1 / dall-e-3).
 */
class OpenAiImageGenerator implements ImageGeneratorInterface
{
    private const string API_URL    = 'https://api.openai.com/v1/images/generations';
    private const string MODEL      = 'gpt-image-1';
    private const string IMAGE_SIZE = '1024x1024';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int                 $photoBuilderOpenAiTimeoutSeconds = 120,
    ) {
    }

    public function generateImage(string $prompt, string $apiKey, ?string $imageSize = null): string
    {
        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $apiKey),
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'         => self::MODEL,
                'prompt'        => $prompt,
                'n'             => 1,
                'size'          => self::IMAGE_SIZE,
                'output_format' => 'png',
            ],
            'max_duration' => $this->photoBuilderOpenAiTimeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new RuntimeException(sprintf(
                'OpenAI image generation API returned status %d: %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        /** @var array{data: list<array{b64_json: string}>} $result */
        $result = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!array_key_exists(0, $result['data'])) {
            throw new RuntimeException('OpenAI image generation API returned unexpected response structure.');
        }

        $imageData = base64_decode($result['data'][0]['b64_json'], true);

        if ($imageData === false) {
            throw new RuntimeException('Failed to decode base64 image data from OpenAI response.');
        }

        return $imageData;
    }
}
