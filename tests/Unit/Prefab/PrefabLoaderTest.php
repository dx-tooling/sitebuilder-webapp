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
            self::assertSame('openai', $result[0]->llmModelProvider);
            self::assertSame('sk-test', $result[0]->llmApiKey);
            self::assertFalse($result[0]->keysVisible);
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
