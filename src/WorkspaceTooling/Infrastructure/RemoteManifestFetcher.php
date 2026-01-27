<?php

declare(strict_types=1);

namespace App\WorkspaceTooling\Infrastructure;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Fetches content asset manifest URLs and merges their "urls" arrays into a single deduplicated list.
 * Only includes entries that are fully qualified absolute URIs (http/https).
 * Logs and skips any manifest that fails to fetch or parse.
 */
final class RemoteManifestFetcher
{
    private const int REQUEST_TIMEOUT = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
    ) {
    }

    /**
     * @param list<string> $manifestUrls
     *
     * @return list<string>
     */
    public function fetchAndMergeAssetUrls(array $manifestUrls): array
    {
        $seen   = [];
        $merged = [];

        foreach ($manifestUrls as $manifestUrl) {
            $manifestUrl = trim($manifestUrl);
            if ($manifestUrl === '') {
                continue;
            }

            try {
                $urls = $this->fetchUrlsFromManifest($manifestUrl);
                foreach ($urls as $url) {
                    $url = trim($url);
                    if ($url !== '' && $this->isFullyQualifiedAbsoluteUri($url) && !array_key_exists($url, $seen)) {
                        $seen[$url] = true;
                        $merged[]   = $url;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to fetch content assets manifest', [
                    'url'   => $manifestUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     *
     * @throws Throwable
     */
    private function fetchUrlsFromManifest(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('HTTP ' . $response->getStatusCode());
        }

        $content = $response->getContent();
        $data    = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !array_key_exists('urls', $data) || !is_array($data['urls'])) {
            throw new RuntimeException('Missing or invalid "urls" array');
        }

        $urls = [];
        foreach ($data['urls'] as $entry) {
            if (is_string($entry)) {
                $urls[] = $entry;
            }
        }

        return $urls;
    }

    private function isFullyQualifiedAbsoluteUri(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !array_key_exists('scheme', $parsed) || !array_key_exists('host', $parsed)) {
            return false;
        }

        return $parsed['scheme'] === 'http' || $parsed['scheme'] === 'https';
    }
}
