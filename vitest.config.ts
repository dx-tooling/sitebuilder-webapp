import { defineConfig } from "vitest/config";

export default defineConfig({
    test: {
        environment: "jsdom",
        include: ["tests/frontend/**/*.test.ts"],
        globals: true,
        setupFiles: ["tests/frontend/setup.ts"],
        // Silence unhandled errors from jsdom (HTMLBaseElement issues with Stimulus)
        dangerouslyIgnoreUnhandledErrors: true,
    },
    resolve: {
        alias: {
            "@controllers": "./src/ChatBasedContentEditor/Presentation/Resources/assets/controllers",
        },
    },
});
