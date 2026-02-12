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
 * Generates images using the Google Gemini image generation API (gemini-3-pro-image-preview).
 */
class GeminiImageGenerator implements ImageGeneratorInterface
{
    private const string API_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int                 $photoBuilderGeminiTimeoutSeconds = 120,
    ) {
    }

    public function generateImage(string $prompt, string $apiKey, string $model, ?string $imageSize = null): string
    {
        $url = sprintf(self::API_URL_TEMPLATE, $model);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-Server-Timeout' => (string) $this->photoBuilderGeminiTimeoutSeconds,
            ],
            'query' => [
                'key' => $apiKey,
            ],
            'json'         => $this->buildRequestBody($prompt, $imageSize),
            'timeout'      => $this->photoBuilderGeminiTimeoutSeconds,
            'max_duration' => $this->photoBuilderGeminiTimeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            throw new RuntimeException(sprintf(
                'Gemini image generation API returned status %d: %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $decoded = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !array_key_exists('candidates', $decoded) || !is_array($decoded['candidates'])) {
            throw new RuntimeException('Gemini image generation API returned unexpected response structure.');
        }

        // Find the first image part in the response
        /** @var list<mixed> $candidates */
        $candidates = $decoded['candidates'];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $content = $candidate['content'] ?? null;

            if (!is_array($content) || !is_array($content['parts'] ?? null)) {
                continue;
            }

            /** @var list<mixed> $parts */
            $parts = $content['parts'];

            foreach ($parts as $part) {
                if (!is_array($part) || !array_key_exists('inlineData', $part)) {
                    continue;
                }

                $inlineData = $part['inlineData'];

                if (!is_array($inlineData) || !is_string($inlineData['data'] ?? null)) {
                    continue;
                }

                $imageData = base64_decode($inlineData['data'], true);

                if ($imageData === false) {
                    throw new RuntimeException('Failed to decode base64 image data from Gemini response.');
                }

                return $imageData;
            }
        }

        throw new RuntimeException('Gemini image generation API returned no image data in response.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(string $prompt, ?string $imageSize): array
    {
        $generationConfig = [
            'responseModalities' => ['IMAGE', 'TEXT'],
            'responseMimeType'   => 'application/json',
        ];

        if ($imageSize !== null) {
            $generationConfig['imageConfig'] = [
                'imageSize' => $imageSize,
            ];
        }

        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => $generationConfig,
        ];
    }
}
