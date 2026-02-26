<?php

declare(strict_types=1);

// "A QA engineer walks into a bar. Orders 1 beer. Orders 0 beers. Orders 99999999 beers.
//  Orders -1 beers. Orders a lizard. Orders NULL beers. First real customer walks in
//  and asks where the bathroom is. The bar bursts into flames." — Brenan Keller

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
