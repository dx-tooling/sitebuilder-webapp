<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;

/**
 * Facade for retrieving information about remote content assets (e.g. images).
 * Used by other verticals to get metadata (dimensions, size, mime type) without downloading the full file.
 */
interface RemoteContentAssetsFacadeInterface
{
    /**
     * Fetch information about a remote image/asset by URL.
     * Uses an efficient approach (HEAD and/or Range request + header parsing) where possible.
     *
     * @return RemoteContentAssetInfoDto|null DTO with url and whatever metadata could be retrieved, or null on failure
     */
    public function getRemoteAssetInfo(string $url): ?RemoteContentAssetInfoDto;
}
