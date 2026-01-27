<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

interface RemoteManifestValidatorInterface
{
    /**
     * Fetches the given URL and checks whether the response is valid JSON
     * with a field "urls" that is a list of fully qualified absolute URIs.
     * Returns false on any failure (network, non-2xx, invalid JSON, wrong shape).
     */
    public function isValidManifestUrl(string $url): bool;
}
