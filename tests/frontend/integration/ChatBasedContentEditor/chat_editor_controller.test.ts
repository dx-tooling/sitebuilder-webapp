import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { Application } from "@hotwired/stimulus";
import ChatEditorController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";

describe("ChatBasedContentEditorController", () => {
    let application: Application;
    const translations = {
        aiBudget: "AI budget: %used% of %max% used",
        estimatedCost: "Estimated cost: $%cost%",
        sendError: "Send failed (%status%)",
        networkError: "Network error",
        pollError: "Polling failed (%status%)",
        connectionRetry: "Connection lost, please retry.",
        makeChanges: "Make changes",
        makingChanges: "Making changes...",
        noResponse: "No response yet.",
        working: "Working",
        thinking: "Thinking",
        askingAi: "Asking AI...",
        aiResponseReceived: "AI response received",
        unknownError: "Unknown error",
        allSet: "All set",
        inProgress: "In progress",
        stop: "Stop",
        stopping: "Stopping...",
        stoppingSlow: "Cancellation is taking longer than expected...",
        cancelled: "Cancelled",
    };

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
    function createChatEditorFixture(
        options: {
            activeSession?: object | null;
            turns?: object[];
            prefillMessage?: string;
            messagesHtml?: string;
        } = {},
    ): HTMLElement {
        const { activeSession = null, turns = [], prefillMessage = "", messagesHtml = "" } = options;

        const container = document.createElement("div");
        container.setAttribute("data-controller", "chat-based-content-editor");
        container.setAttribute("data-chat-based-content-editor-run-url-value", "/api/run");
        container.setAttribute("data-chat-based-content-editor-poll-url-template-value", "/api/poll/__SESSION_ID__");
        container.setAttribute(
            "data-chat-based-content-editor-cancel-url-template-value",
            "/api/cancel/__SESSION_ID__",
        );
        container.setAttribute("data-chat-based-content-editor-conversation-id-value", "test-conv-123");
        container.setAttribute("data-chat-based-content-editor-context-usage-url-value", "/api/context");
        container.setAttribute(
            "data-chat-based-content-editor-context-usage-value",
            JSON.stringify({ usedTokens: 100, maxTokens: 1000 }),
        );
        container.setAttribute("data-chat-based-content-editor-active-session-value", JSON.stringify(activeSession));
        container.setAttribute("data-chat-based-content-editor-turns-value", JSON.stringify(turns));
        container.setAttribute("data-chat-based-content-editor-translations-value", JSON.stringify(translations));
        container.setAttribute("data-chat-based-content-editor-prefill-message-value", prefillMessage);

        container.innerHTML = `
            <div data-chat-based-content-editor-target="messages" class="messages-container">
                ${messagesHtml || '<p class="text-dark-500">Messages will appear here.</p>'}
            </div>
            <div data-chat-based-content-editor-target="contextUsage">
                <span data-chat-based-content-editor-target="contextUsageText">AI budget: 0 of 1000 used</span>
                <div data-chat-based-content-editor-target="contextUsageBar" style="width: 0%"></div>
                <span data-chat-based-content-editor-target="contextUsageCost">$0.00</span>
            </div>
            <form data-action="submit->chat-based-content-editor#handleSubmit">
                <input type="hidden" name="_csrf_token" value="csrf-123">
                <textarea data-chat-based-content-editor-target="instruction"></textarea>
                <button type="submit" data-chat-based-content-editor-target="submit">Make changes</button>
                <button type="button" data-chat-based-content-editor-target="cancelButton" class="hidden">Stop</button>
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
            const element = document.querySelector(
                '[data-controller="chat-based-content-editor"]',
            ) as HTMLElement | null;
            expect(element).not.toBeNull();
            const controller = application.getControllerForElementAndIdentifier(
                element as HTMLElement,
                "chat-based-content-editor",
            );
            expect(controller).toBeTruthy();
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

    describe("interactive flow", () => {
        it("submits instruction, calls run endpoint, and polls session output", async () => {
            vi.stubGlobal(
                "fetch",
                vi.fn(async (input: RequestInfo | URL) => {
                    const url = String(input);
                    if (url === "/api/context") {
                        return {
                            ok: true,
                            json: async () => ({ usedTokens: 100, maxTokens: 1000, totalCost: 0 }),
                        };
                    }

                    if (url === "/api/run") {
                        return {
                            ok: true,
                            status: 200,
                            json: async () => ({ sessionId: "sess-1" }),
                        };
                    }

                    if (url === "/api/poll/sess-1?after=0") {
                        return {
                            ok: true,
                            status: 200,
                            json: async () => ({
                                chunks: [
                                    {
                                        id: 1,
                                        chunkType: "text",
                                        payload: JSON.stringify({ content: "Applied update." }),
                                    },
                                    { id: 2, chunkType: "done", payload: JSON.stringify({ success: true }) },
                                ],
                                lastId: 2,
                                status: "completed",
                                contextUsage: { usedTokens: 110, maxTokens: 1000, totalCost: 0.01 },
                            }),
                        };
                    }

                    return {
                        ok: true,
                        status: 200,
                        json: async () => ({ chunks: [], lastId: 0, status: "completed" }),
                    };
                }),
            );

            createChatEditorFixture();
            await waitForController();

            const textarea = document.querySelector(
                "[data-chat-based-content-editor-target='instruction']",
            ) as HTMLTextAreaElement;
            textarea.value = "Update the hero section copy";

            const form = document.querySelector("form") as HTMLFormElement;
            form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

            await vi.waitFor(() => {
                const messages = document.querySelector("[data-chat-based-content-editor-target='messages']");
                expect(messages?.textContent).toContain("Applied update.");
            });

            const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
            expect(fetchMock).toHaveBeenCalledWith(
                "/api/run",
                expect.objectContaining({
                    method: "POST",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                }),
            );
            expect(fetchMock).toHaveBeenCalledWith(
                "/api/poll/sess-1?after=0",
                expect.objectContaining({
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                }),
            );
        });
    });
});
