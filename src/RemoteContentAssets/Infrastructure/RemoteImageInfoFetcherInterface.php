<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;

interface RemoteImageInfoFetcherInterface
{
    public function fetch(string $url): ?RemoteContentAssetInfoDto;
}
