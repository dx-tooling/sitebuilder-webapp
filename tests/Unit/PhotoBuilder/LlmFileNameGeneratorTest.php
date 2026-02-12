<?php

declare(strict_types=1);

namespace Tests\Unit\PhotoBuilder;

use App\LlmContentEditor\Facade\Enum\LlmModelProvider;
use App\PhotoBuilder\Infrastructure\Adapter\LlmFileNameGenerator;
use App\PhotoBuilder\TestHarness\FakeFileNameGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LlmFileNameGeneratorTest extends TestCase
{
    public function testFakeFileNameGeneratorReturnsDeterministicFileName(): void
    {
        $generator = new FakeFileNameGenerator(new NullLogger());
        $result    = $generator->generateFileName('A sunset over the ocean', 'fake-key', LlmModelProvider::OpenAI);

        self::assertSame('fake-generated-image.png', $result);
    }

    public function testFallsBackToSlugOnInvalidApiKey(): void
    {
        // Using an empty/invalid API key will cause the LLM call to fail,
        // triggering the slug-based fallback.
        $generator = new LlmFileNameGenerator(new NullLogger());
        $result    = $generator->generateFileName(
            'A modern office with natural lighting',
            '',
            LlmModelProvider::OpenAI,
        );

        // Should fall back to a slug-based filename
        self::assertStringEndsWith('.png', $result);
        self::assertStringContainsString('modern-office', $result);
    }

    public function testFallbackReturnsDefaultForEmptyPrompt(): void
    {
        $generator = new LlmFileNameGenerator(new NullLogger());
        $result    = $generator->generateFileName('', '', LlmModelProvider::OpenAI);

        self::assertSame('generated-image.png', $result);
    }
}
