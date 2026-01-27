<?php

declare(strict_types=1);

namespace App\Tests\Unit\RemoteContentAssets;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacade;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;
use PHPUnit\Framework\TestCase;

final class RemoteContentAssetsFacadeTest extends TestCase
{
    public function testGetRemoteAssetInfoReturnsNullWhenFetcherReturnsNull(): void
    {
        $fetcher = $this->createMock(RemoteImageInfoFetcherInterface::class);
        $fetcher->method('fetch')->willReturn(null);
        $facade = new RemoteContentAssetsFacade($fetcher);

        self::assertNull($facade->getRemoteAssetInfo('https://example.com/missing.png'));
    }

    public function testGetRemoteAssetInfoReturnsDtoWhenFetcherReturnsDto(): void
    {
        $dto = new RemoteContentAssetInfoDto(
            'https://example.com/cat.jpg',
            100,
            200,
            'image/jpeg',
            1234
        );
        $fetcher = $this->createMock(RemoteImageInfoFetcherInterface::class);
        $fetcher->method('fetch')->willReturn($dto);
        $facade = new RemoteContentAssetsFacade($fetcher);

        $result = $facade->getRemoteAssetInfo('https://example.com/cat.jpg');

        self::assertInstanceOf(RemoteContentAssetInfoDto::class, $result);
        self::assertSame('https://example.com/cat.jpg', $result->url);
        self::assertSame(100, $result->width);
        self::assertSame(200, $result->height);
        self::assertSame('image/jpeg', $result->mimeType);
        self::assertSame(1234, $result->sizeInBytes);
    }
}
