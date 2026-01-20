<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Infrastructure\Provider\Dto;

use Throwable;

/**
 * DTO representing an error rule for the fake provider.
 */
readonly class ErrorRuleDto
{
    public function __construct(
        public string    $pattern,
        public Throwable $exception
    ) {
    }
}
