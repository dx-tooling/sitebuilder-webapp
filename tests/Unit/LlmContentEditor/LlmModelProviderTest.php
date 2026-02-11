<?php

declare(strict_types=1);

namespace App\Tests\Unit\LlmContentEditor;

use App\LlmContentEditor\Domain\Enum\LlmModelName;
use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use PHPUnit\Framework\TestCase;

final class LlmModelProviderTest extends TestCase
{
    public function testOpenAiProviderSupportedTextModelsContainsGpt52(): void
    {
        $models = LlmModelProvider::OpenAI->supportedTextModels();

        self::assertNotEmpty($models);
        self::assertContains(LlmModelName::Gpt52, $models);
    }

    public function testGoogleProviderSupportedTextModelsContainsGemini(): void
    {
        $models = LlmModelProvider::Google->supportedTextModels();

        self::assertNotEmpty($models);
        self::assertContains(LlmModelName::Gemini3ProPreview, $models);
    }

    public function testAllProviderCasesHaveDisplayName(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertNotEmpty($provider->displayName());
        }
    }

    public function testAllProviderCasesHaveAtLeastOneSupportedTextModel(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertNotEmpty($provider->supportedTextModels());
        }
    }

    public function testAllProviderCasesHaveImageGenerationModel(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertTrue($provider->imageGenerationModel()->isImageGenerationModel());
        }
    }

    public function testAllProviderCasesHaveImagePromptGenerationModel(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertFalse($provider->imagePromptGenerationModel()->isImageGenerationModel());
        }
    }

    public function testOnlyOpenAiSupportsContentEditing(): void
    {
        self::assertTrue(LlmModelProvider::OpenAI->supportsContentEditing());
        self::assertFalse(LlmModelProvider::Google->supportsContentEditing());
    }

    public function testAllProvidersSupportPhotoBuilder(): void
    {
        foreach (LlmModelProvider::cases() as $provider) {
            self::assertTrue($provider->supportsPhotoBuilder());
        }
    }
}
