/**
 * Vitest setup file for frontend tests.
 *
 * This file runs before each test file and sets up the test environment.
 */

import { beforeEach, afterEach } from "vitest";

// Suppress jsdom HTMLBaseElement errors that occur with Stimulus
// These are benign errors from jsdom's DOM implementation when util.inspect
// tries to format DOM elements that have incompatible property accessors
const originalConsoleError = console.error;
console.error = (...args: unknown[]) => {
    const message = String(args[0] ?? "");
    if (message.includes("HTMLBaseElement") || message.includes("HTMLAnchorElement")) {
        return; // Suppress
    }
    originalConsoleError.apply(console, args);
};

// Catch unhandled errors from jsdom/Stimulus incompatibility
if (typeof process !== "undefined") {
    process.on("uncaughtException", (error: Error) => {
        if (error.message?.includes("HTMLBaseElement") || error.message?.includes("HTMLAnchorElement")) {
            // Suppress jsdom errors
            return;
        }
        throw error;
    });
}

// Reset DOM between tests
beforeEach(() => {
    document.body.innerHTML = "";
});

afterEach(() => {
    // Clean up any lingering elements
    document.body.innerHTML = "";
    document.head.innerHTML = "";
});
