<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

/**
 * Interface for uploading assets to S3.
 */
interface S3AssetUploaderInterface
{
    /**
     * Upload a file to S3.
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
    ): string;

    /**
     * Verify that the S3 credentials are valid and have appropriate permissions.
     *
     * @param string      $bucketName      The S3 bucket name
     * @param string      $region          The AWS region
     * @param string      $accessKeyId     The AWS access key ID
     * @param string      $secretAccessKey The AWS secret access key
     * @param string|null $iamRoleArn      Optional IAM role to assume
     *
     * @return bool True if credentials are valid and bucket is accessible
     */
    public function verifyCredentials(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): bool;
}
