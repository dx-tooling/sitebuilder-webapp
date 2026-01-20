<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * Factory that creates and manages a singleton FakeAIProvider instance for testing.
 * Provides methods to seed deterministic behaviors.
 */
final class FakeAIProviderFactory implements AIProviderFactoryInterface
{
    private ?FakeAIProvider $provider = null;

    public function createProvider(): AIProviderInterface
    {
        if ($this->provider === null) {
            $this->provider = new FakeAIProvider();
        }

        return $this->provider;
    }

    /**
     * Get the fake provider instance for seeding.
     */
    public function getFakeProvider(): FakeAIProvider
    {
        $provider = $this->createProvider();
        assert($provider instanceof FakeAIProvider);

        return $provider;
    }

    /**
     * Seed a response rule that matches when message content contains the pattern.
     */
    public function seedResponse(string $messagePattern, string|AssistantMessage $response): void
    {
        $this->getFakeProvider()->seedResponse($messagePattern, $response);
    }

    /**
     * Seed a tool call rule that triggers when message content contains the pattern.
     * The real tool will be executed.
     *
     * @phpstan-ignore-next-line - toolInputs is an associative array (tool parameter map)
     */
    public function seedToolCall(string $messagePattern, string $toolName, array $toolInputs): void
    {
        $this->getFakeProvider()->seedToolCall($messagePattern, $toolName, $toolInputs);
    }

    /**
     * Seed a response rule that triggers after a tool executes.
     * The pattern matches against the tool result content.
     */
    public function seedPostToolResponse(string $toolResultPattern, string|AssistantMessage $response): void
    {
        $this->getFakeProvider()->seedPostToolResponse($toolResultPattern, $response);
    }

    /**
     * Seed an error rule that throws when message content contains the pattern.
     */
    public function seedError(string $messagePattern, Throwable $error): void
    {
        $this->getFakeProvider()->seedError($messagePattern, $error);
    }

    /**
     * Clear all seeded rules.
     */
    public function clearRules(): void
    {
        $this->getFakeProvider()->clearRules();
    }
}
