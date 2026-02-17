<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\TestHarness;

use App\RemoteContentAssets\Infrastructure\RemoteManifestValidatorInterface;

/**
 * E2E/test double: accepts any non-empty http(s) manifest URL without making HTTP requests.
 */
final class SimulatedRemoteManifestValidator implements RemoteManifestValidatorInterface
{
    public function isValidManifestUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? null;

        return $scheme === 'http' || $scheme === 'https';
    }
}
