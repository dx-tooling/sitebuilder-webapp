<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\TestHarness;

use App\RemoteContentAssets\Infrastructure\S3AssetUploaderInterface;

/**
 * E2E/test double: no AWS calls; verifyCredentials returns true, upload returns a deterministic fake URL.
 */
final class SimulatedS3AssetUploader implements S3AssetUploaderInterface
{
    private const string FAKE_UPLOAD_BASE = 'https://e2e-simulated.s3.example.com';

    public function verifyCredentials(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): bool {
        return true;
    }

    public function upload(
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
        $prefix = $keyPrefix !== null && $keyPrefix !== '' ? trim($keyPrefix, '/') . '/' : '';
        $key    = $prefix . $filename;

        return self::FAKE_UPLOAD_BASE . '/' . $key;
    }
}
