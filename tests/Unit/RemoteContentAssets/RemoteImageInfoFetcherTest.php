<?php

declare(strict_types=1);

namespace App\Tests\Unit\RemoteContentAssets;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RemoteImageInfoFetcherTest extends TestCase
{
    private const string MINIMAL_1X1_GIF_BODY = 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

    public function testFetchReturnsNullForEmptyUrl(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $logger  = $this->createMock(LoggerInterface::class);
        $fetcher = new RemoteImageInfoFetcher($httpClient, $logger);

        self::assertNull($fetcher->fetch(''));
    }

    public function testFetchReturnsNullWhenRequestThrows(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException(new RuntimeException('Network error'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Failed to fetch remote content asset info',
            self::callback(function (array $context): bool {
                return array_key_exists('url', $context)
                    && array_key_exists('error', $context)
                    && $context['url']   === 'https://example.com/img.png'
                    && $context['error'] === 'Network error';
            })
        );
        $fetcher = new RemoteImageInfoFetcher($httpClient, $logger);

        self::assertNull($fetcher->fetch('https://example.com/img.png'));
    }

    public function testFetchReturnsDtoWithDimensionsAndSizeFrom206Response(): void
    {
        $body = base64_decode(self::MINIMAL_1X1_GIF_BODY, true);
        self::assertNotFalse($body, 'Minimal 1x1 GIF must decode');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(206);
        $response->method('getHeaders')->willReturn([
            'Content-Range' => ['bytes 0-42/999'],
            'Content-Type'  => ['image/gif'],
        ]);
        $response->method('getContent')->willReturn($body);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $logger  = $this->createMock(LoggerInterface::class);
        $fetcher = new RemoteImageInfoFetcher($httpClient, $logger);

        $result = $fetcher->fetch('https://example.com/pixel.gif');

        self::assertInstanceOf(RemoteContentAssetInfoDto::class, $result);
        self::assertSame('https://example.com/pixel.gif', $result->url);
        self::assertSame(1, $result->width);
        self::assertSame(1, $result->height);
        self::assertSame('image/gif', $result->mimeType);
        self::assertSame(999, $result->sizeInBytes);
    }

    public function testFetchUsesContentLengthWhenNot206(): void
    {
        $body = base64_decode(self::MINIMAL_1X1_GIF_BODY, true);
        self::assertNotFalse($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn([
            'Content-Length' => ['43'],
            'Content-Type'   => ['image/gif; charset=binary'],
        ]);
        $response->method('getContent')->willReturn($body);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $logger  = $this->createMock(LoggerInterface::class);
        $fetcher = new RemoteImageInfoFetcher($httpClient, $logger);

        $result = $fetcher->fetch('https://example.com/photo.gif');

        self::assertInstanceOf(RemoteContentAssetInfoDto::class, $result);
        self::assertSame(43, $result->sizeInBytes);
        self::assertSame('image/gif', $result->mimeType);
    }
}
