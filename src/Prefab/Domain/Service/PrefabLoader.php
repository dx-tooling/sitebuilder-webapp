<?php

declare(strict_types=1);

namespace App\Prefab\Domain\Service;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\Prefab\Facade\Dto\PrefabDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Loads prefab definitions from prefabs.yaml at project root.
 * Returns empty list if file is missing or invalid; logs and skips invalid entries.
 */
final class PrefabLoader
{
    private const string FILENAME = 'prefabs.yaml';

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * @return list<PrefabDto>
     */
    public function load(): array
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        if (!is_string($projectDir)) {
            return [];
        }
        $path = $projectDir . '/' . self::FILENAME;

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (Throwable $e) {
            $this->logger->warning('Prefab config parse failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (!is_array($data) || !array_key_exists('prefabs', $data) || !is_array($data['prefabs'])) {
            return [];
        }

        $result = [];
        foreach ($data['prefabs'] as $index => $item) {
            if (!is_array($item)) {
                $this->logger->warning('Prefab entry skipped: not an array', ['index' => $index]);

                continue;
            }

            $entry = $item;
            /** @var array<string, mixed> $entry */
            $dto = $this->parseEntry($entry, $index);
            if ($dto !== null) {
                $result[] = $dto;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function parseEntry(array $item, int $index): ?PrefabDto
    {
        $name                = array_key_exists('name', $item)               && is_string($item['name']) ? trim($item['name']) : null;
        $projectLink         = array_key_exists('project_link', $item)       && is_string($item['project_link']) ? trim($item['project_link']) : null;
        $githubAccessKey     = array_key_exists('github_access_key', $item)  && is_string($item['github_access_key']) ? $item['github_access_key'] : null;
        $llmModelProviderRaw = array_key_exists('llm_model_provider', $item) && is_string($item['llm_model_provider']) ? trim($item['llm_model_provider']) : null;
        $llmApiKey           = array_key_exists('llm_api_key', $item)        && is_string($item['llm_api_key']) ? $item['llm_api_key'] : null;
        $keysVisible         = true;
        if (array_key_exists('keys_visible', $item) && is_bool($item['keys_visible'])) {
            $keysVisible = $item['keys_visible'];
        }

        if ($name === null || $name === '' || $projectLink === null || $projectLink === '' || $githubAccessKey === null || $llmModelProviderRaw === null || $llmApiKey === null) {
            $this->logger->warning('Prefab entry skipped: missing or invalid required fields', [
                'index' => $index,
                'keys'  => array_keys($item),
            ]);

            return null;
        }

        $llmModelProvider = LlmModelProvider::tryFrom($llmModelProviderRaw);
        if ($llmModelProvider === null) {
            $this->logger->warning('Prefab entry skipped: unknown llm_model_provider', [
                'index' => $index,
                'value' => $llmModelProviderRaw,
            ]);

            return null;
        }

        return new PrefabDto(
            $name,
            $projectLink,
            $githubAccessKey,
            $llmModelProvider->value,
            $llmApiKey,
            $keysVisible
        );
    }
}
