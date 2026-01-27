<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Fetches metadata for a remote image (dimensions, mime type, size) in an efficient way:
 * requests only the first bytes (Range) when the server supports it, then parses image headers.
 */
final class RemoteImageInfoFetcher implements RemoteImageInfoFetcherInterface
{
    private const int REQUEST_TIMEOUT = 10;

    /** Bytes to request for parsing image dimensions (enough for common formats). */
    private const int IMAGE_HEADER_BYTES = 16384;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
    ) {
    }

    public function fetch(string $url): ?RemoteContentAssetInfoDto
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Range' => 'bytes=0-' . (self::IMAGE_HEADER_BYTES - 1),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $headers    = $response->getHeaders();

            $mimeType    = $this->getFirstHeader($headers, 'content-type');
            $sizeInBytes = $this->resolveSize($statusCode, $headers);
            $body        = $response->getContent();

            $width  = null;
            $height = null;
            $parsed = getimagesizefromstring($body);
            if ($parsed !== false) {
                $width  = (int) $parsed[0];
                $height = (int) $parsed[1];
                if ($mimeType === null) {
                    $mimeType = $parsed['mime'];
                }
            }

            if ($mimeType !== null) {
                $mimeType = $this->stripContentTypeParams($mimeType);
            }

            return new RemoteContentAssetInfoDto(
                $url,
                $width,
                $height,
                $mimeType,
                $sizeInBytes
            );
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch remote content asset info', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function getFirstHeader(array $headers, string $key): ?string
    {
        $key = strtolower($key);
        foreach ($headers as $name => $values) {
            if (strtolower($name) === $key && $values !== []) {
                return $values[0];
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function resolveSize(int $statusCode, array $headers): ?int
    {
        if ($statusCode === 206) {
            $contentRange = $this->getFirstHeader($headers, 'content-range');
            if ($contentRange !== null && preg_match('#bytes \d+-\d+/(\d+)#', $contentRange, $m)) {
                return (int) $m[1];
            }
        }
        $contentLength = $this->getFirstHeader($headers, 'content-length');
        if ($contentLength !== null && $contentLength !== '' && is_numeric($contentLength)) {
            return (int) $contentLength;
        }

        return null;
    }

    private function stripContentTypeParams(string $contentType): string
    {
        $pos = strpos($contentType, ';');
        if ($pos !== false) {
            return trim(substr($contentType, 0, $pos));
        }

        return trim($contentType);
    }
}
