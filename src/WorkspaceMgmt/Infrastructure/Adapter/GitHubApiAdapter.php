<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_key_exists;
use function is_string;

/**
 * GitHub API adapter using Symfony HttpClient.
 */
final class GitHubApiAdapter implements GitHubAdapterInterface
{
    private const string API_BASE_URL = 'https://api.github.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function findPullRequestForBranch(
        string $owner,
        string $repo,
        string $branchName,
        string $token
    ): ?string {
        $response = $this->httpClient->request(
            'GET',
            self::API_BASE_URL . '/repos/' . $owner . '/' . $repo . '/pulls',
            [
                'headers' => $this->getHeaders($token),
                'query'   => [
                    'head'  => $owner . ':' . $branchName,
                    'state' => 'open',
                ],
            ]
        );

        /** @var list<array<string, mixed>> $data */
        $data = $response->toArray();

        if ($data === []) {
            return null;
        }

        $firstPr = $data[0];
        if (!array_key_exists('html_url', $firstPr) || !is_string($firstPr['html_url'])) {
            return null;
        }

        return $firstPr['html_url'];
    }

    public function createPullRequest(
        string $owner,
        string $repo,
        string $branchName,
        string $title,
        string $body,
        string $token
    ): string {
        $response = $this->httpClient->request(
            'POST',
            self::API_BASE_URL . '/repos/' . $owner . '/' . $repo . '/pulls',
            [
                'headers' => $this->getHeaders($token),
                'json'    => [
                    'title' => $title,
                    'body'  => $body,
                    'head'  => $branchName,
                    'base'  => 'main',
                ],
            ]
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 201) {
            throw new RuntimeException('Failed to create pull request: HTTP ' . $statusCode);
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        if (!array_key_exists('html_url', $data) || !is_string($data['html_url'])) {
            throw new RuntimeException('Invalid response from GitHub API');
        }

        return $data['html_url'];
    }

    /**
     * @return array<string, string>
     */
    private function getHeaders(string $token): array
    {
        return [
            'Accept'               => 'application/vnd.github+json',
            'Authorization'        => 'Bearer ' . $token,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }
}
