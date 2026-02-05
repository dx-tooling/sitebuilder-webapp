<?php

declare(strict_types=1);

namespace App\Tests\Unit\RemoteContentAssets;

use App\RemoteContentAssets\Facade\Dto\RemoteContentAssetInfoDto;
use App\RemoteContentAssets\Facade\RemoteContentAssetsFacade;
use App\RemoteContentAssets\Infrastructure\RemoteImageInfoFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestFetcherInterface;
use App\RemoteContentAssets\Infrastructure\RemoteManifestValidatorInterface;
use App\RemoteContentAssets\Infrastructure\S3AssetUploaderInterface;
use PHPUnit\Framework\TestCase;

final class RemoteContentAssetsFacadeTest extends TestCase
{
    public function testGetRemoteAssetInfoReturnsNullWhenFetcherReturnsNull(): void
    {
        $fetcher = $this->createMock(RemoteImageInfoFetcherInterface::class);
        $fetcher->method('fetch')->willReturn(null);
        $facade = $this->createFacade($fetcher);

        self::assertNull($facade->getRemoteAssetInfo('https://example.com/missing.png'));
    }

    public function testGetRemoteAssetInfoReturnsDtoWhenFetcherReturnsDto(): void
    {
        $dto = new RemoteContentAssetInfoDto(
            'https://example.com/cat.jpg',
            100,
            200,
            'image/jpeg',
            1234
        );
        $fetcher = $this->createMock(RemoteImageInfoFetcherInterface::class);
        $fetcher->method('fetch')->willReturn($dto);
        $facade = $this->createFacade($fetcher);

        $result = $facade->getRemoteAssetInfo('https://example.com/cat.jpg');

        self::assertInstanceOf(RemoteContentAssetInfoDto::class, $result);
        self::assertSame('https://example.com/cat.jpg', $result->url);
        self::assertSame(100, $result->width);
        self::assertSame(200, $result->height);
        self::assertSame('image/jpeg', $result->mimeType);
        self::assertSame(1234, $result->sizeInBytes);
    }

    public function testIsValidManifestUrlDelegatesToValidator(): void
    {
        $validator = $this->createMock(RemoteManifestValidatorInterface::class);
        $validator->method('isValidManifestUrl')
            ->with('https://example.com/manifest.json')
            ->willReturn(true);
        $facade = $this->createFacadeWithValidator($validator);

        self::assertTrue($facade->isValidManifestUrl('https://example.com/manifest.json'));
    }

    public function testIsValidManifestUrlReturnsFalseWhenValidatorReturnsFalse(): void
    {
        $validator = $this->createMock(RemoteManifestValidatorInterface::class);
        $validator->method('isValidManifestUrl')->willReturn(false);
        $facade = $this->createFacadeWithValidator($validator);

        self::assertFalse($facade->isValidManifestUrl('https://example.com/invalid.json'));
    }

    public function testFetchAndMergeAssetUrlsDelegatesToFetcher(): void
    {
        $manifestUrls = ['https://example.com/manifest1.json', 'https://example.com/manifest2.json'];
        $expectedUrls = ['https://example.com/a.png', 'https://example.com/b.jpg'];

        $fetcher = $this->createMock(RemoteManifestFetcherInterface::class);
        $fetcher->method('fetchAndMergeAssetUrls')
            ->with($manifestUrls)
            ->willReturn($expectedUrls);
        $facade = $this->createFacadeWithManifestFetcher($fetcher);

        $result = $facade->fetchAndMergeAssetUrls($manifestUrls);

        self::assertSame($expectedUrls, $result);
    }

    public function testFetchAndMergeAssetUrlsReturnsEmptyArrayForEmptyInput(): void
    {
        $fetcher = $this->createMock(RemoteManifestFetcherInterface::class);
        $fetcher->method('fetchAndMergeAssetUrls')
            ->with([])
            ->willReturn([]);
        $facade = $this->createFacadeWithManifestFetcher($fetcher);

        self::assertSame([], $facade->fetchAndMergeAssetUrls([]));
    }

    public function testUploadAssetDelegatesToS3Uploader(): void
    {
        $expectedUrl = 'https://bucket.s3.eu-central-1.amazonaws.com/prefix/20260127/abc123_image.jpg';

        $uploader = $this->createMock(S3AssetUploaderInterface::class);
        $uploader->expects($this->once())
            ->method('upload')
            ->with(
                'my-bucket',
                'eu-central-1',
                'AKIAIOSFODNN7EXAMPLE',
                'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'arn:aws:iam::123456789012:role/S3UploadRole',
                'prefix',
                'image.jpg',
                'file-contents',
                'image/jpeg'
            )
            ->willReturn($expectedUrl);

        $facade = $this->createFacadeWithS3Uploader($uploader);

        $result = $facade->uploadAsset(
            'my-bucket',
            'eu-central-1',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'arn:aws:iam::123456789012:role/S3UploadRole',
            'prefix',
            'image.jpg',
            'file-contents',
            'image/jpeg'
        );

        self::assertSame($expectedUrl, $result);
    }

    public function testUploadAssetPassesNullValuesCorrectly(): void
    {
        $expectedUrl = 'https://bucket.s3.eu-central-1.amazonaws.com/image.jpg';

        $uploader = $this->createMock(S3AssetUploaderInterface::class);
        $uploader->expects($this->once())
            ->method('upload')
            ->with(
                'my-bucket',
                'eu-central-1',
                'AKIAIOSFODNN7EXAMPLE',
                'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                null, // no IAM role
                null, // no key prefix
                'image.jpg',
                'file-contents',
                'image/jpeg'
            )
            ->willReturn($expectedUrl);

        $facade = $this->createFacadeWithS3Uploader($uploader);

        $result = $facade->uploadAsset(
            'my-bucket',
            'eu-central-1',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            null,
            null,
            'image.jpg',
            'file-contents',
            'image/jpeg'
        );

        self::assertSame($expectedUrl, $result);
    }

    private function createFacade(?RemoteImageInfoFetcherInterface $imageInfoFetcher = null): RemoteContentAssetsFacade
    {
        return new RemoteContentAssetsFacade(
            $imageInfoFetcher ?? $this->createMock(RemoteImageInfoFetcherInterface::class),
            $this->createMock(RemoteManifestValidatorInterface::class),
            $this->createMock(RemoteManifestFetcherInterface::class),
            $this->createMock(S3AssetUploaderInterface::class)
        );
    }

    private function createFacadeWithValidator(RemoteManifestValidatorInterface $validator): RemoteContentAssetsFacade
    {
        return new RemoteContentAssetsFacade(
            $this->createMock(RemoteImageInfoFetcherInterface::class),
            $validator,
            $this->createMock(RemoteManifestFetcherInterface::class),
            $this->createMock(S3AssetUploaderInterface::class)
        );
    }

    private function createFacadeWithManifestFetcher(RemoteManifestFetcherInterface $fetcher): RemoteContentAssetsFacade
    {
        return new RemoteContentAssetsFacade(
            $this->createMock(RemoteImageInfoFetcherInterface::class),
            $this->createMock(RemoteManifestValidatorInterface::class),
            $fetcher,
            $this->createMock(S3AssetUploaderInterface::class)
        );
    }

    private function createFacadeWithS3Uploader(S3AssetUploaderInterface $uploader): RemoteContentAssetsFacade
    {
        return new RemoteContentAssetsFacade(
            $this->createMock(RemoteImageInfoFetcherInterface::class),
            $this->createMock(RemoteManifestValidatorInterface::class),
            $this->createMock(RemoteManifestFetcherInterface::class),
            $uploader
        );
    }
}
