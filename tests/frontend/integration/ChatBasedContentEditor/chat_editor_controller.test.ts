import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { Application } from "@hotwired/stimulus";
import ChatEditorController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";

describe("ChatBasedContentEditorController", () => {
    let application: Application;

    beforeEach(() => {
        // Reset DOM
        document.body.innerHTML = "";

        // Mock fetch globally to prevent network errors
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ chunks: [], lastId: 0, status: "completed" }),
            }),
        );

        // Create Stimulus application
        application = Application.start();
        application.register("chat-based-content-editor", ChatEditorController);
    });

    afterEach(async () => {
        // Stop Stimulus application first
        application.stop();
        await Promise.resolve();
        // Clear DOM before jsdom cleanup
        document.body.innerHTML = "";
        document.head.innerHTML = "";
        vi.restoreAllMocks();
    });

    /**
     * Helper to create a basic chat editor DOM fixture.
     */
    function createChatEditorFixture(options: { activeSession?: object | null; turns?: object[] } = {}): HTMLElement {
        const { activeSession = null, turns = [] } = options;

        const container = document.createElement("div");
        container.setAttribute("data-controller", "chat-based-content-editor");
        container.setAttribute("data-chat-based-content-editor-run-url-value", "/api/run");
        container.setAttribute("data-chat-based-content-editor-poll-url-template-value", "/api/poll/__SESSION_ID__");
        container.setAttribute("data-chat-based-content-editor-conversation-id-value", "test-conv-123");
        container.setAttribute("data-chat-based-content-editor-context-usage-url-value", "/api/context");
        container.setAttribute(
            "data-chat-based-content-editor-context-usage-value",
            JSON.stringify({ usedTokens: 100, maxTokens: 1000 }),
        );
        container.setAttribute("data-chat-based-content-editor-active-session-value", JSON.stringify(activeSession));
        container.setAttribute("data-chat-based-content-editor-turns-value", JSON.stringify(turns));

        container.innerHTML = `
            <div data-chat-based-content-editor-target="messages" class="messages-container">
                <p class="text-dark-500">Messages will appear here.</p>
            </div>
            <div data-chat-based-content-editor-target="contextUsage">
                <span data-chat-based-content-editor-target="contextUsageText">AI budget: 0 of 1000 used</span>
                <div data-chat-based-content-editor-target="contextUsageBar" style="width: 0%"></div>
                <span data-chat-based-content-editor-target="contextUsageCost">$0.00</span>
            </div>
            <form>
                <textarea data-chat-based-content-editor-target="instruction"></textarea>
                <button type="submit" data-chat-based-content-editor-target="submit">Make changes</button>
            </form>
        `;

        document.body.appendChild(container);
        return container;
    }

    /**
     * Helper to wait for Stimulus controller to connect and process.
     */
    async function waitForController(): Promise<void> {
        await vi.waitFor(() => {
            const submitButton = document.querySelector(
                "[data-chat-based-content-editor-target='submit']",
            ) as HTMLButtonElement | null;
            expect(submitButton).not.toBeNull();
        });
    }

    describe("initialization", () => {
        it("should connect and update context bar", async () => {
            createChatEditorFixture();
            await waitForController();

            const contextText = document.querySelector("[data-chat-based-content-editor-target='contextUsageText']");
            expect(contextText?.textContent).toContain("100");
        });

        it("should have submit button enabled when no active session", async () => {
            createChatEditorFixture();
            await waitForController();

            const submitButton = document.querySelector(
                "[data-chat-based-content-editor-target='submit']",
            ) as HTMLButtonElement;
            expect(submitButton.disabled).toBe(false);
            expect(submitButton.textContent).toBe("Make changes");
        });
    });

    describe("completed turns rendering", () => {
        it("should not render technical container for turns without events", async () => {
            const container = document.createElement("div");
            container.setAttribute("data-controller", "chat-based-content-editor");
            container.setAttribute("data-chat-based-content-editor-run-url-value", "/api/run");
            container.setAttribute(
                "data-chat-based-content-editor-poll-url-template-value",
                "/api/poll/__SESSION_ID__",
            );
            container.setAttribute("data-chat-based-content-editor-conversation-id-value", "test-conv-123");
            container.setAttribute("data-chat-based-content-editor-context-usage-url-value", "/api/context");
            container.setAttribute(
                "data-chat-based-content-editor-context-usage-value",
                JSON.stringify({ usedTokens: 100, maxTokens: 1000 }),
            );
            container.setAttribute("data-chat-based-content-editor-active-session-value", "null");
            container.setAttribute(
                "data-chat-based-content-editor-turns-value",
                JSON.stringify([
                    {
                        instruction: "Simple question",
                        response: "Simple answer",
                        status: "completed",
                        events: [],
                    },
                ]),
            );

            container.innerHTML = `
                <div data-chat-based-content-editor-target="messages" class="messages-container">
                    <div class="flex justify-end">
                        <div class="max-w-[85%] rounded-lg px-4 py-2">Simple question</div>
                    </div>
                    <div class="flex justify-start">
                        <div class="max-w-[85%] rounded-lg px-4 py-2">Simple answer</div>
                    </div>
                </div>
                <form>
                    <textarea data-chat-based-content-editor-target="instruction"></textarea>
                    <button type="submit" data-chat-based-content-editor-target="submit">Make changes</button>
                </form>
            `;

            document.body.appendChild(container);
            await waitForController();

            // Should NOT have technical container
            const technicalContainer = document.querySelector(".technical-messages-container");
            expect(technicalContainer).toBeNull();
        });
    });
});
