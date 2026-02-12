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
     * Number of assets to display at a time in the browser UI (windowed rendering).
     */
    public const int BROWSER_WINDOW_SIZE = 10;

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

    /**
     * Verify that S3 credentials are valid and the bucket is accessible.
     *
     * @param string      $bucketName      The S3 bucket name
     * @param string      $region          The AWS region
     * @param string      $accessKeyId     The AWS access key ID
     * @param string      $secretAccessKey The AWS secret access key
     * @param string|null $iamRoleArn      Optional IAM role to assume
     *
     * @return bool True if credentials are valid and bucket is accessible
     */
    public function verifyS3Credentials(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): bool;

    /**
     * Check which of the given filenames are present in any of the manifest URLs.
     * Matches by basename only (folder/path prefix is irrelevant).
     *
     * @param list<string> $manifestUrls
     * @param list<string> $fileNames    Basenames to look for (e.g. "00fa0883_office.png")
     *
     * @return list<string> The subset of $fileNames that were found
     */
    public function findAvailableFileNames(array $manifestUrls, array $fileNames): array;

    /**
     * Upload an asset to S3.
     *
     * @param string      $bucketName      The S3 bucket name
     * @param string      $region          The AWS region
     * @param string      $accessKeyId     The AWS access key ID
     * @param string      $secretAccessKey The AWS secret access key
     * @param string|null $iamRoleArn      Optional IAM role to assume
     * @param string|null $keyPrefix       Optional key prefix (folder) for uploads
     * @param string      $filename        The original filename
     * @param string      $contents        The file contents
     * @param string      $mimeType        The MIME type of the file
     *
     * @return string The public URL of the uploaded file
     */
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
    ): string;
}
