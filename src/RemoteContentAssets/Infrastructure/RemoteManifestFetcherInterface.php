<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

interface RemoteManifestFetcherInterface
{
    /**
     * Fetches content asset manifest URLs and merges their "urls" arrays into a single deduplicated list.
     * Only includes entries that are fully qualified absolute URIs (http/https).
     * Logs and skips any manifest that fails to fetch or parse.
     *
     * @param list<string> $manifestUrls
     *
     * @return list<string>
     */
    public function fetchAndMergeAssetUrls(array $manifestUrls): array;
}
