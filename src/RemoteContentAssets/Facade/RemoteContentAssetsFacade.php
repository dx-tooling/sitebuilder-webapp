<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestValidatorInterface;
use App\RemoteContentAssets\Infrastructure\S3AssetUploaderInterface;

use function array_key_exists;
use function basename;
use function parse_url;

use const PHP_URL_PATH;

final class RemoteContentAssetsFacade implements RemoteContentAssetsFacadeInterface
{
    public function __construct(
        private readonly RemoteImageInfoFetcherInterface  $imageInfoFetcher,
        private readonly RemoteManifestValidatorInterface $manifestValidator,
        private readonly RemoteManifestFetcherInterface   $manifestFetcher,
        private readonly S3AssetUploaderInterface         $s3AssetUploader,
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

    /**
     * @param list<string> $manifestUrls
     * @param list<string> $fileNames
     *
     * @return list<string>
     */
    public function findAvailableFileNames(array $manifestUrls, array $fileNames): array
    {
        if ($fileNames === [] || $manifestUrls === []) {
            return [];
        }

        $allUrls = $this->manifestFetcher->fetchAndMergeAssetUrls($manifestUrls);

        $availableBasenames = [];
        foreach ($allUrls as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path !== null && $path !== false) {
                $availableBasenames[basename($path)] = true;
            }
        }

        $found = [];
        foreach ($fileNames as $fileName) {
            if (array_key_exists($fileName, $availableBasenames)) {
                $found[] = $fileName;
            }
        }

        return $found;
    }

    public function verifyS3Credentials(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): bool {
        return $this->s3AssetUploader->verifyCredentials(
            $bucketName,
            $region,
            $accessKeyId,
            $secretAccessKey,
            $iamRoleArn
        );
    }

    public function uploadAsset(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn,
        ?string $keyPrefix,
        string  $filename,
        string  $contents,
        string  $mimeType
    ): string {
        return $this->s3AssetUploader->upload(
            $bucketName,
            $region,
            $accessKeyId,
            $secretAccessKey,
            $iamRoleArn,
            $keyPrefix,
            $filename,
            $contents,
            $mimeType
        );
    }
}
