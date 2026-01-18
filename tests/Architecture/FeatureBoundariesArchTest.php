<?php

declare(strict_types=1);

$features = array_filter(
    array_map(
        static fn (
            string $path
        ): string => basename($path),
        glob(__DIR__ . '/../../src/*', GLOB_ONLYDIR)
    ),
    static fn (
        string $dir
    ): bool => true
);

foreach ($features as $from) {
    foreach ($features as $to) {
        if ($from === $to) {
            continue;
        }

        arch("{$from} must not use {$to} internals")
            ->expect("App\\{$from}")
            ->classes()
            ->not->toUse([
                "App\\{$to}\\Api",
                "App\\{$to}\\Domain",
                "App\\{$to}\\Infrastructure",
                "App\\{$to}\\Presentation",
                "App\\{$to}\\TestHarness",
            ])
                 ->group('architecture');
    }
}
