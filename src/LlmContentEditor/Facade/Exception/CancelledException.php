<?php

declare(strict_types=1);

namespace App\LlmContentEditor\Facade\Exception;

use RuntimeException;

final class CancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cancelled by user.');
    }
}
