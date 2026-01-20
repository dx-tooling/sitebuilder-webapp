<?php

declare(strict_types=1);

namespace App\Tests\TestHelpers;

use App\LlmContentEditor\Infrastructure\Provider\FakeAIProviderFactory;
use NeuronAI\Chat\Messages\AssistantMessage;
use Throwable;

/**
 * Helper class for seeding fake AI provider behaviors in tests.
 */
final class FakeAIProviderSeeder
{
    /**
     * Seed the fake provider factory with rules.
     *
     * @phpstan-ignore-next-line - rules is an associative array (configuration map)
     */
    public static function seed(FakeAIProviderFactory $factory, array $rules): void
    {
        // Seed direct responses
        if (array_key_exists('response', $rules) && is_array($rules['response'])) {
            /** @var list<array<string, mixed>> $responseRules */
            $responseRules = $rules['response'];
            foreach ($responseRules as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('content', $rule)
                ) {
                    $content = $rule['content'];
                    if (is_string($content)) {
                        $factory->seedResponse($rule['pattern'], $content);
                    } elseif (is_array($content)) {
                        /** @var list<mixed>|array<int, mixed> $contentArray */
                        $contentArray = $content;
                        $factory->seedResponse($rule['pattern'], new AssistantMessage($contentArray));
                    }
                }
            }
        }

        // Seed tool calls
        if (array_key_exists('tool_call', $rules) && is_array($rules['tool_call'])) {
            /** @var list<array<string, mixed>> $toolCallRules */
            $toolCallRules = $rules['tool_call'];
            foreach ($toolCallRules as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('tool', $rule) && is_string($rule['tool'])
                                                       && array_key_exists('inputs', $rule) && is_array($rule['inputs'])
                ) {
                    /** @var array<string, mixed> $inputs */
                    $inputs = $rule['inputs'];
                    $factory->seedToolCall($rule['pattern'], $rule['tool'], $inputs);
                }
            }
        }

        // Seed post-tool responses
        if (array_key_exists('post_tool_response', $rules) && is_array($rules['post_tool_response'])) {
            /** @var list<array<string, mixed>> $postToolResponseRules */
            $postToolResponseRules = $rules['post_tool_response'];
            foreach ($postToolResponseRules as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('content', $rule)
                ) {
                    $content = $rule['content'];
                    if (is_string($content)) {
                        $factory->seedPostToolResponse($rule['pattern'], $content);
                    } elseif (is_array($content)) {
                        /** @var list<mixed>|array<int, mixed> $contentArray */
                        $contentArray = $content;
                        $factory->seedPostToolResponse($rule['pattern'], new AssistantMessage($contentArray));
                    }
                }
            }
        }

        // Seed errors
        if (array_key_exists('error', $rules) && is_array($rules['error'])) {
            /** @var list<array<string, mixed>> $errorRules */
            $errorRules = $rules['error'];
            foreach ($errorRules as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('exception', $rule) && $rule['exception'] instanceof Throwable
                ) {
                    $factory->seedError($rule['pattern'], $rule['exception']);
                }
            }
        }
    }

    /**
     * Clear all rules from the fake provider factory.
     */
    public static function clear(FakeAIProviderFactory $factory): void
    {
        $factory->clearRules();
    }
}
