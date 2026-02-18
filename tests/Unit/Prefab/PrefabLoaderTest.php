<?php

declare(strict_types=1);

namespace App\Tests\Unit\Prefab;

use App\Prefab\Domain\Service\PrefabLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class PrefabLoaderTest extends TestCase
{
    public function testLoadReturnsEmptyWhenFileMissing(): void
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')->with('kernel.project_dir')->willReturn('/nonexistent');
        $logger = $this->createMock(LoggerInterface::class);

        $loader = new PrefabLoader($parameterBag, $logger);
        $result = $loader->load();

        self::assertSame([], $result);
    }

    public function testLoadReturnsPrefabsWhenValidYaml(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prefab_loader_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $yamlPath = $tmpDir . '/prefabs.yaml';
        $yaml     = <<<'YAML'
prefabs:
  - name: "Test Prefab"
    project_link: "https://github.com/test/repo.git"
    github_access_key: "ghp_test"
    llm_model_provider: "openai"
    llm_api_key: "sk-test"
    keys_visible: false
YAML;
        file_put_contents($yamlPath, $yaml);

        try {
            $parameterBag = $this->createMock(ParameterBagInterface::class);
            $parameterBag->method('get')->with('kernel.project_dir')->willReturn($tmpDir);
            $logger = $this->createMock(LoggerInterface::class);

            $loader = new PrefabLoader($parameterBag, $logger);
            $result = $loader->load();

            self::assertCount(1, $result);
            self::assertSame('Test Prefab', $result[0]->name);
            self::assertSame('https://github.com/test/repo.git', $result[0]->projectLink);
            self::assertSame('ghp_test', $result[0]->githubAccessKey);
            self::assertSame('openai', $result[0]->contentEditingLlmModelProvider);
            self::assertSame('sk-test', $result[0]->contentEditingLlmApiKey);
            self::assertFalse($result[0]->keysVisible);
            self::assertNull($result[0]->photoBuilderLlmModelProvider);
            self::assertNull($result[0]->photoBuilderLlmApiKey);
        } finally {
            if (is_file($yamlPath)) {
                unlink($yamlPath);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testLoadPrefabWithDedicatedPhotoBuilderKeys(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prefab_loader_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $yamlPath = $tmpDir . '/prefabs.yaml';
        $yaml     = <<<'YAML'
prefabs:
  - name: "Prefab with Gemini"
    project_link: "https://github.com/example/repo.git"
    github_access_key: "ghp_xxx"
    llm_model_provider: "openai"
    llm_api_key: "sk-xxx"
    photo_builder_llm_model_provider: "google"
    photo_builder_llm_api_key: "AIza_xxx"
    keys_visible: false
YAML;
        file_put_contents($yamlPath, $yaml);

        try {
            $parameterBag = $this->createMock(ParameterBagInterface::class);
            $parameterBag->method('get')->with('kernel.project_dir')->willReturn($tmpDir);
            $logger = $this->createMock(LoggerInterface::class);

            $loader = new PrefabLoader($parameterBag, $logger);
            $result = $loader->load();

            self::assertCount(1, $result);
            self::assertSame('Prefab with Gemini', $result[0]->name);
            self::assertSame('google', $result[0]->photoBuilderLlmModelProvider);
            self::assertSame('AIza_xxx', $result[0]->photoBuilderLlmApiKey);
        } finally {
            if (is_file($yamlPath)) {
                unlink($yamlPath);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testLoadSkipsEntryWhenOnlyPhotoBuilderProviderSet(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prefab_loader_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $yamlPath = $tmpDir . '/prefabs.yaml';
        $yaml     = <<<'YAML'
prefabs:
  - name: "Valid One"
    project_link: "https://github.com/a/repo.git"
    github_access_key: "ghp_a"
    llm_model_provider: "openai"
    llm_api_key: "sk-a"
  - name: "Invalid photo_builder only provider"
    project_link: "https://github.com/b/repo.git"
    github_access_key: "ghp_b"
    llm_model_provider: "openai"
    llm_api_key: "sk-b"
    photo_builder_llm_model_provider: "google"
YAML;
        file_put_contents($yamlPath, $yaml);

        try {
            $parameterBag = $this->createMock(ParameterBagInterface::class);
            $parameterBag->method('get')->with('kernel.project_dir')->willReturn($tmpDir);
            $logger = $this->createMock(LoggerInterface::class);

            $loader = new PrefabLoader($parameterBag, $logger);
            $result = $loader->load();

            self::assertCount(1, $result);
            self::assertSame('Valid One', $result[0]->name);
        } finally {
            if (is_file($yamlPath)) {
                unlink($yamlPath);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    public function testLoadSkipsEntryWhenInvalidPhotoBuilderProvider(): void
    {
        $tmpDir = sys_get_temp_dir() . '/prefab_loader_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $yamlPath = $tmpDir . '/prefabs.yaml';
        $yaml     = <<<'YAML'
prefabs:
  - name: "Bad provider"
    project_link: "https://github.com/c/repo.git"
    github_access_key: "ghp_c"
    llm_model_provider: "openai"
    llm_api_key: "sk-c"
    photo_builder_llm_model_provider: "anthropic"
    photo_builder_llm_api_key: "key"
YAML;
        file_put_contents($yamlPath, $yaml);

        try {
            $parameterBag = $this->createMock(ParameterBagInterface::class);
            $parameterBag->method('get')->with('kernel.project_dir')->willReturn($tmpDir);
            $logger = $this->createMock(LoggerInterface::class);

            $loader = new PrefabLoader($parameterBag, $logger);
            $result = $loader->load();

            self::assertCount(0, $result);
        } finally {
            if (is_file($yamlPath)) {
                unlink($yamlPath);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }
}
