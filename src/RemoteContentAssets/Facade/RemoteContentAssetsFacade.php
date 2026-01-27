<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;

final class RemoteContentAssetsFacade implements RemoteContentAssetsFacadeInterface
{
    public function __construct(
        private readonly RemoteImageInfoFetcherInterface $fetcher,
    ) {
    }

    public function getRemoteAssetInfo(string $url): ?RemoteContentAssetInfoDto
    {
        return $this->fetcher->fetch($url);
    }
}
