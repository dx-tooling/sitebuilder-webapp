<?php

declare(strict_types=1);

namespace App\Tests\Unit\RemoteContentAssets;

use App\RemoteContentAssets\Infrastructure\RemoteManifestValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RemoteManifestValidatorTest extends TestCase
{
    public function testIsValidManifestUrlReturnsFalseForEmptyUrl(): void
    {
        $validator = $this->createValidator(null);

        self::assertFalse($validator->isValidManifestUrl(''));
    }

    public function testIsValidManifestUrlReturnsTrueForValidManifestResponse(): void
    {
        $body = (string) json_encode([
            'urls' => [
                'https://example.com/foo.png',
                'https://example.com/bar/baz.js',
            ],
        ]);
        $validator = $this->createValidator(200, $body);

        self::assertTrue($validator->isValidManifestUrl('https://example.com/manifest.json'));
    }

    public function testIsValidManifestUrlReturnsFalseWhenUrlsIsNotArrayOfStrings(): void
    {
        $body = (string) json_encode([
            'urls' => [1, 2, 3],
        ]);
        $validator = $this->createValidator(200, $body);

        self::assertFalse($validator->isValidManifestUrl('https://example.com/manifest.json'));
    }

    public function testIsValidManifestUrlReturnsFalseWhenUrlsMissing(): void
    {
        $body      = (string) json_encode(['version' => 1]);
        $validator = $this->createValidator(200, $body);

        self::assertFalse($validator->isValidManifestUrl('https://example.com/manifest.json'));
    }

    public function testIsValidManifestUrlReturnsFalseOnNon200(): void
    {
        $validator = $this->createValidator(404, 'Not Found');

        self::assertFalse($validator->isValidManifestUrl('https://example.com/manifest.json'));
    }

    private function createValidator(?int $statusCode = null, ?string $content = null): RemoteManifestValidator
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode ?? 200);
        $response->method('getContent')->willReturn($content ?? '{}');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        return new RemoteManifestValidator($client);
    }
}
