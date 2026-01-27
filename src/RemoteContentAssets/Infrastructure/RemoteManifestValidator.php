<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class RemoteManifestValidator implements RemoteManifestValidatorInterface
{
    private const int REQUEST_TIMEOUT = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function isValidManifestUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (!$this->isAllowedScheme($url)) {
            return false;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $content = $response->getContent();
            $data    = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !array_key_exists('urls', $data) || !is_array($data['urls'])) {
                return false;
            }

            foreach ($data['urls'] as $entry) {
                if (!is_string($entry) || !$this->isFullyQualifiedAbsoluteUri($entry)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isAllowedScheme(string $url): bool
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? null;

        return $scheme === 'http' || $scheme === 'https';
    }

    private function isFullyQualifiedAbsoluteUri(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false || !array_key_exists('scheme', $parsed) || !array_key_exists('host', $parsed)) {
            return false;
        }

        return $parsed['scheme'] === 'http' || $parsed['scheme'] === 'https';
    }
}
