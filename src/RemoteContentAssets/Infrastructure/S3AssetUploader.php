<?php

declare(strict_types=1);

namespace App\RemoteContentAssets\Infrastructure;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function bin2hex;
use function pathinfo;
use function random_bytes;
use function rtrim;
use function sprintf;
use function time;

use const PATHINFO_EXTENSION;

/**
 * Uploads assets to AWS S3.
 */
final class S3AssetUploader implements S3AssetUploaderInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
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
        $s3Client = $this->createS3Client($region, $accessKeyId, $secretAccessKey, $iamRoleArn);

        // Generate a unique key for the file
        $key = $this->generateUniqueKey($keyPrefix, $filename);

        try {
            $s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $key,
                'Body'        => $contents,
                'ContentType' => $mimeType,
            ]);

            // Generate the public URL
            return sprintf('https://%s.s3.%s.amazonaws.com/%s', $bucketName, $region, $key);
        } catch (AwsException $e) {
            $this->logger->error('Failed to upload file to S3', [
                'bucket'    => $bucketName,
                'key'       => $key,
                'error'     => $e->getMessage(),
                'errorCode' => $e->getAwsErrorCode(),
            ]);

            throw new RuntimeException('Failed to upload file to S3: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verifyCredentials(
        string  $bucketName,
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): bool {
        try {
            $s3Client = $this->createS3Client($region, $accessKeyId, $secretAccessKey, $iamRoleArn);

            // Try to head the bucket - this verifies:
            // 1. Credentials are valid
            // 2. Bucket exists
            // 3. We have at least read access
            $s3Client->headBucket([
                'Bucket' => $bucketName,
            ]);

            return true;
        } catch (AwsException $e) {
            $this->logger->warning('S3 credentials verification failed', [
                'bucket'    => $bucketName,
                'region'    => $region,
                'error'     => $e->getMessage(),
                'errorCode' => $e->getAwsErrorCode(),
            ]);

            return false;
        }
    }

    /**
     * Create an S3 client, optionally assuming an IAM role.
     */
    private function createS3Client(
        string  $region,
        string  $accessKeyId,
        string  $secretAccessKey,
        ?string $iamRoleArn
    ): S3Client {
        $credentials = new Credentials($accessKeyId, $secretAccessKey);

        // If an IAM role is specified, assume it
        if ($iamRoleArn !== null && $iamRoleArn !== '') {
            $credentials = $this->assumeRole($region, $credentials, $iamRoleArn);
        }

        return new S3Client([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => $credentials,
        ]);
    }

    /**
     * Assume an IAM role and return temporary credentials.
     */
    private function assumeRole(string $region, Credentials $credentials, string $roleArn): Credentials
    {
        $stsClient = new StsClient([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => $credentials,
        ]);

        $result = $stsClient->assumeRole([
            'RoleArn'         => $roleArn,
            'RoleSessionName' => 'sitebuilder-upload-' . time(),
            'DurationSeconds' => 3600, // 1 hour
        ]);

        /** @var array{AccessKeyId: string, SecretAccessKey: string, SessionToken: string} $assumedCredentials */
        $assumedCredentials = $result['Credentials'];

        return new Credentials(
            $assumedCredentials['AccessKeyId'],
            $assumedCredentials['SecretAccessKey'],
            $assumedCredentials['SessionToken']
        );
    }

    /**
     * Generate a unique key for the uploaded file.
     * Format: [prefix/]uploads/YYYYMMDD/randomhex_originalname.ext.
     */
    private function generateUniqueKey(?string $keyPrefix, string $filename): string
    {
        $date      = date('Ymd');
        $randomHex = bin2hex(random_bytes(8));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Sanitize filename - keep only alphanumeric, dash, underscore
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $basename) ?? '';
        $basename = mb_substr($basename, 0, 50); // Limit length

        $key = sprintf('uploads/%s/%s_%s', $date, $randomHex, $basename);
        if ($extension !== '') {
            $key .= '.' . $extension;
        }

        if ($keyPrefix !== null && $keyPrefix !== '') {
            $key = rtrim($keyPrefix, '/') . '/' . $key;
        }

        return $key;
    }
}
