<?php

declare(strict_types=1);

namespace App\Tests\TestHelpers;

use App\LlmContentEditor\Infrastructure\Provider\Dto\ErrorRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\FakeProviderSeedingRulesDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\PostToolResponseRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ResponseRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolCallRuleDto;
use App\LlmContentEditor\Infrastructure\Provider\Dto\ToolInputsDto;
use App\LlmContentEditor\Infrastructure\Provider\FakeAIProviderFactory;
use NeuronAI\Chat\Messages\AssistantMessage;
use Throwable;

/**
 * Helper class for seeding fake AI provider behaviors in tests.
 */
final class FakeAIProviderSeeder
{
    /**
     * Seed the fake provider factory with rules using DTOs.
     */
    public static function seed(FakeAIProviderFactory $factory, FakeProviderSeedingRulesDto $rules): void
    {
        // Seed direct responses
        foreach ($rules->responseRules as $rule) {
            $factory->seedResponse($rule->pattern, $rule->content);
        }

        // Seed tool calls
        foreach ($rules->toolCallRules as $rule) {
            $factory->seedToolCall($rule->pattern, $rule->toolName, $rule->toolInputs);
        }

        // Seed post-tool responses
        foreach ($rules->postToolResponseRules as $rule) {
            $factory->seedPostToolResponse($rule->pattern, $rule->content);
        }

        // Seed errors
        foreach ($rules->errorRules as $rule) {
            $factory->seedError($rule->pattern, $rule->exception);
        }
    }

    /**
     * Seed the fake provider factory with rules from an associative array (legacy method for backward compatibility).
     *
     * @phpstan-ignore-next-line - rules is an associative array (legacy format)
     */
    public static function seedFromArray(FakeAIProviderFactory $factory, array $rules): void
    {
        $responseRules         = [];
        $toolCallRules         = [];
        $postToolResponseRules = [];
        $errorRules            = [];

        // Parse direct responses
        if (array_key_exists('response', $rules) && is_array($rules['response'])) {
            /** @var list<array<string, mixed>> $responseRulesArray */
            $responseRulesArray = $rules['response'];
            foreach ($responseRulesArray as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('content', $rule)
                ) {
                    $content = $rule['content'];
                    if (is_string($content)) {
                        $responseRules[] = new ResponseRuleDto($rule['pattern'], $content);
                    } elseif (is_array($content)) {
                        /** @var list<mixed>|array<int, mixed> $contentArray */
                        $contentArray    = $content;
                        $responseRules[] = new ResponseRuleDto($rule['pattern'], new AssistantMessage($contentArray));
                    }
                }
            }
        }

        // Parse tool calls
        if (array_key_exists('tool_call', $rules) && is_array($rules['tool_call'])) {
            /** @var list<array<string, mixed>> $toolCallRulesArray */
            $toolCallRulesArray = $rules['tool_call'];
            foreach ($toolCallRulesArray as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('tool', $rule) && is_string($rule['tool'])
                                                       && array_key_exists('inputs', $rule) && is_array($rule['inputs'])
                ) {
                    /** @var array<string, mixed> $inputs */
                    $inputs          = $rule['inputs'];
                    $toolCallRules[] = new ToolCallRuleDto(
                        $rule['pattern'],
                        $rule['tool'],
                        ToolInputsDto::fromArray($inputs)
                    );
                }
            }
        }

        // Parse post-tool responses
        if (array_key_exists('post_tool_response', $rules) && is_array($rules['post_tool_response'])) {
            /** @var list<array<string, mixed>> $postToolResponseRulesArray */
            $postToolResponseRulesArray = $rules['post_tool_response'];
            foreach ($postToolResponseRulesArray as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('content', $rule)
                ) {
                    $content = $rule['content'];
                    if (is_string($content)) {
                        $postToolResponseRules[] = new PostToolResponseRuleDto($rule['pattern'], $content);
                    } elseif (is_array($content)) {
                        /** @var list<mixed>|array<int, mixed> $contentArray */
                        $contentArray            = $content;
                        $postToolResponseRules[] = new PostToolResponseRuleDto($rule['pattern'], new AssistantMessage($contentArray));
                    }
                }
            }
        }

        // Parse errors
        if (array_key_exists('error', $rules) && is_array($rules['error'])) {
            /** @var list<array<string, mixed>> $errorRulesArray */
            $errorRulesArray = $rules['error'];
            foreach ($errorRulesArray as $rule) {
                if (
                    array_key_exists('pattern', $rule) && is_string($rule['pattern'])
                                                       && array_key_exists('exception', $rule) && $rule['exception'] instanceof Throwable
                ) {
                    $errorRules[] = new ErrorRuleDto($rule['pattern'], $rule['exception']);
                }
            }
        }

        self::seed($factory, new FakeProviderSeedingRulesDto($responseRules, $toolCallRules, $postToolResponseRules, $errorRules));
    }

    /**
     * Clear all rules from the fake provider factory.
     */
    public static function clear(FakeAIProviderFactory $factory): void
    {
        $factory->clearRules();
    }
}
