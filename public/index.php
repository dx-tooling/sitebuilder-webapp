<?php

declare(strict_types=1);

use App\Kernel;
use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Enum\Timezone;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

date_default_timezone_set(Timezone::UTC->value);

return function (
    array $context
) {
    if (!is_string($context['APP_ENV'])) {
        throw new ValueError('APP_ENV should be a string');
    }

    return new Kernel($context['APP_ENV'], (bool)$context['APP_DEBUG']);
};
