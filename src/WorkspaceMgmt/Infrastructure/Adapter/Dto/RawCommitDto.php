<?php

declare(strict_types=1);

namespace App\WorkspaceMgmt\Infrastructure\Adapter\Dto;

/**
 * Raw commit data from git adapter.
 * Internal DTO used between adapter and service layer.
 */
final readonly class RawCommitDto
{
    public function __construct(
        public string $hash,
        public string $subject,
        public string $body,
        public string $timestamp,
    ) {
    }
}
