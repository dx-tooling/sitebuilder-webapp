<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Facade;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestValidatorInterface;
use App\RemoteContentAssets\Infrastructure\S3AssetUploaderInterface;

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
