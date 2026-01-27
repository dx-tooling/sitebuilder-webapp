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

    /**
     * Validate a manifest URL - checks if it returns valid JSON with a "urls" array
     * of fully qualified absolute URIs (http/https).
     * Returns false on any failure (network, non-2xx, invalid JSON, wrong shape).
     */
    public function isValidManifestUrl(string $url): bool;

    /**
     * Fetch asset URLs from multiple manifests, merging and deduplicating.
     * Invalid or unreachable manifests are skipped (logged as warnings).
     *
     * @param list<string> $manifestUrls
     *
     * @return list<string>
     */
    public function fetchAndMergeAssetUrls(array $manifestUrls): array;
}
