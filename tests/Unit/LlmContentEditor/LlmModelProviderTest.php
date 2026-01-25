<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use PHPUnit\Framework\TestCase;

final class LlmModelProviderTest extends TestCase
{
    public function testOpenAiProviderSupportedModelsContainsGpt52(): void
    {
        $models = LlmModelProvider::OpenAI->supportedModels();

        self::assertNotEmpty($models);
        self::assertContains(LlmModelName::Gpt52, $models);
    }

    public function testAllProviderCasesHaveDisplayName(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertNotEmpty($provider->displayName());
        }
    }

    public function testAllProviderCasesHaveAtLeastOneSupportedModel(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertNotEmpty($provider->supportedModels());
        }
    }
}
