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

// Common is a shared vertical for cross-cutting concerns and can be used by other verticals
$sharedVerticals = ['Common'];

foreach ($features as $from) {
    foreach ($features as $to) {
        if ($from === $to) {
            continue;
        }

        // Skip rules where the target is a shared vertical that can be used by others
        if (in_array($to, $sharedVerticals, true)) {
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
