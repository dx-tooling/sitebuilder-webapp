import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { Application } from "@hotwired/stimulus";
import LlmKeyVerificationController from "../../../../src/ProjectMgmt/Presentation/Resources/assets/controllers/llm_key_verification_controller.ts";

describe("LlmKeyVerificationController", () => {
    let application: Application;

    beforeEach(() => {
        // Reset the DOM
        document.body.innerHTML = "";

        // Create and start Stimulus app
        application = Application.start();
        application.register("llm-key-verification", LlmKeyVerificationController);
    });

    afterEach(() => {
        // Stop the application to clean up
        application.stop();
        vi.restoreAllMocks();
    });

    const createControllerElement = async (apiKey: string = ""): Promise<HTMLDivElement> => {
        const html = `
            <div data-controller="llm-key-verification"
                 data-llm-key-verification-verify-url-value="/projects/verify-llm-key"
                 data-llm-key-verification-csrf-token-value="test-csrf-token">
                <input type="radio" name="llm_model_provider" value="openai" checked>
                <input type="password"
                       data-llm-key-verification-target="input"
                       data-action="blur->llm-key-verification#verify"
                       value="${apiKey}">
                <div data-llm-key-verification-target="status">
                    <div class="hidden" data-llm-key-verification-target="spinner">Verifying...</div>
                    <div class="hidden" data-llm-key-verification-target="success">Success!</div>
                    <div class="hidden" data-llm-key-verification-target="error">Error!</div>
                </div>
            </div>
        `;
        document.body.innerHTML = html;

        // Wait for Stimulus to connect the controller
        await new Promise((resolve) => setTimeout(resolve, 50));

        return document.body.firstElementChild as HTMLDivElement;
    };

    it("should not verify when input is empty", async () => {
        await createControllerElement("");

        // Mock fetch
        const fetchSpy = vi.spyOn(global, "fetch");

        // Trigger blur on input
        const input = document.querySelector('[data-llm-key-verification-target="input"]') as HTMLInputElement;
        input.dispatchEvent(new Event("blur"));

        // Wait a tick for async operations
        await new Promise((resolve) => setTimeout(resolve, 50));

        // Fetch should not have been called
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it("should show spinner during verification", async () => {
        await createControllerElement("sk-test-key");

        // Mock fetch with a delayed response
        const fetchMock = vi
            .spyOn(global, "fetch")
            .mockImplementation(
                () =>
                    new Promise((resolve) =>
                        setTimeout(() => resolve(new Response(JSON.stringify({ success: true }))), 200),
                    ),
            );

        // Trigger blur on input
        const input = document.querySelector('[data-llm-key-verification-target="input"]') as HTMLInputElement;
        input.dispatchEvent(new Event("blur"));

        // Wait a bit for the spinner to show (but not for fetch to complete)
        await new Promise((resolve) => setTimeout(resolve, 50));

        // Spinner should be visible
        const spinner = document.querySelector('[data-llm-key-verification-target="spinner"]') as HTMLElement;
        expect(spinner.classList.contains("hidden")).toBe(false);

        // Wait for fetch to complete to avoid cleanup issues
        await new Promise((resolve) => setTimeout(resolve, 200));
        fetchMock.mockRestore();
    });

    it("should show success state after successful verification", async () => {
        await createControllerElement("sk-test-key");

        // Mock fetch with success response
        vi.spyOn(global, "fetch").mockResolvedValue(new Response(JSON.stringify({ success: true })));

        // Trigger blur on input
        const input = document.querySelector('[data-llm-key-verification-target="input"]') as HTMLInputElement;
        input.dispatchEvent(new Event("blur"));

        // Wait for async operations
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Success should be visible
        const success = document.querySelector('[data-llm-key-verification-target="success"]') as HTMLElement;
        const error = document.querySelector('[data-llm-key-verification-target="error"]') as HTMLElement;

        expect(success.classList.contains("hidden")).toBe(false);
        expect(error.classList.contains("hidden")).toBe(true);
    });

    it("should show error state after failed verification", async () => {
        await createControllerElement("sk-invalid-key");

        // Mock fetch with failure response
        vi.spyOn(global, "fetch").mockResolvedValue(new Response(JSON.stringify({ success: false })));

        // Trigger blur on input
        const input = document.querySelector('[data-llm-key-verification-target="input"]') as HTMLInputElement;
        input.dispatchEvent(new Event("blur"));

        // Wait for async operations
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Error should be visible
        const success = document.querySelector('[data-llm-key-verification-target="success"]') as HTMLElement;
        const error = document.querySelector('[data-llm-key-verification-target="error"]') as HTMLElement;

        expect(success.classList.contains("hidden")).toBe(true);
        expect(error.classList.contains("hidden")).toBe(false);
    });

    it("should send correct data in verification request", async () => {
        await createControllerElement("sk-test-key");

        // Mock fetch
        const fetchMock = vi.spyOn(global, "fetch").mockResolvedValue(new Response(JSON.stringify({ success: true })));

        // Trigger blur on input
        const input = document.querySelector('[data-llm-key-verification-target="input"]') as HTMLInputElement;
        input.dispatchEvent(new Event("blur"));

        // Wait for async operations
        await new Promise((resolve) => setTimeout(resolve, 100));

        // Verify fetch was called with correct parameters
        expect(fetchMock).toHaveBeenCalledWith(
            "/projects/verify-llm-key",
            expect.objectContaining({
                method: "POST",
                headers: expect.objectContaining({
                    "X-Requested-With": "XMLHttpRequest",
                }),
            }),
        );

        // Verify FormData contents
        const call = fetchMock.mock.calls[0];
        const formData = call[1]?.body as FormData;
        expect(formData.get("provider")).toBe("openai");
        expect(formData.get("api_key")).toBe("sk-test-key");
        expect(formData.get("_csrf_token")).toBe("test-csrf-token");
    });
});
