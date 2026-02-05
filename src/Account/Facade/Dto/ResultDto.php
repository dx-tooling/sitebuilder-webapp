<?php

declare(strict_types=1);

namespace App\Account\Facade\Dto;

final readonly class ResultDto
{
    public function __construct(
        public bool    $isSuccess,
        public ?string $errorMessage = null,
        public ?string $userId = null
    ) {
    }
}
