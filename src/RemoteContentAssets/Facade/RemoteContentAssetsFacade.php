<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestValidatorInterface;

final class RemoteContentAssetsFacade implements RemoteContentAssetsFacadeInterface
{
    public function __construct(
        private readonly RemoteImageInfoFetcherInterface  $imageInfoFetcher,
        private readonly RemoteManifestValidatorInterface $manifestValidator,
        private readonly RemoteManifestFetcherInterface   $manifestFetcher,
    ) {
    }

    public function getRemoteAssetInfo(string $url): ?RemoteContentAssetInfoDto
    {
        return $this->imageInfoFetcher->fetch($url);
    }

    public function isValidManifestUrl(string $url): bool
    {
        return $this->manifestValidator->isValidManifestUrl($url);
    }

    /**
     * @param list<string> $manifestUrls
     *
     * @return list<string>
     */
    public function fetchAndMergeAssetUrls(array $manifestUrls): array
    {
        return $this->manifestFetcher->fetchAndMergeAssetUrls($manifestUrls);
    }
}
