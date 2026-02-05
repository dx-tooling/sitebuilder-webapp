import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import HtmlEditorController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/html_editor_controller.ts";

/**
 * Unit tests for the HTML Editor Stimulus controller.
 * Tests the editor functionality for viewing and editing HTML dist files.
 */

interface MockControllerState {
    loadUrlValue: string;
    saveUrlValue: string;
    workspaceIdValue: string;
    translationsValue: {
        editing: string;
        saveChanges: string;
        close: string;
        saving: string;
        saveSuccess: string;
        saveError: string;
        loadError: string;
    };
    hasContainerTarget: boolean;
    containerTarget: HTMLElement | null;
    hasTextareaTarget: boolean;
    textareaTarget: HTMLTextAreaElement | null;
    hasPageNameDisplayTarget: boolean;
    pageNameDisplayTarget: HTMLElement | null;
    hasSaveButtonTarget: boolean;
    saveButtonTarget: HTMLButtonElement | null;
    hasCloseButtonTarget: boolean;
    closeButtonTarget: HTMLButtonElement | null;
    hasLoadingTarget: boolean;
    loadingTarget: HTMLElement | null;
    hasErrorTarget: boolean;
    errorTarget: HTMLElement | null;
    hasSuccessTarget: boolean;
    successTarget: HTMLElement | null;
    hasChatAreaTarget: boolean;
    chatAreaTarget: HTMLElement | null;
    currentPath: string;
    csrfToken: string;
}

const defaultTranslations = {
    editing: "Editing",
    saveChanges: "Save changes",
    close: "Close",
    saving: "Saving...",
    saveSuccess: "Changes saved successfully",
    saveError: "Failed to save changes",
    loadError: "Failed to load content",
};

const createController = (overrides: Partial<MockControllerState> = {}): HtmlEditorController => {
    const controller = Object.create(HtmlEditorController.prototype) as HtmlEditorController;
    const state = controller as unknown as MockControllerState;

    // Default values
    state.loadUrlValue = "/workspace/test-id/page-content";
    state.saveUrlValue = "/workspace/test-id/save-page";
    state.workspaceIdValue = "test-workspace-id";
    state.translationsValue = defaultTranslations;

    // Default targets (not present)
    state.hasContainerTarget = false;
    state.containerTarget = null;
    state.hasTextareaTarget = false;
    state.textareaTarget = null;
    state.hasPageNameDisplayTarget = false;
    state.pageNameDisplayTarget = null;
    state.hasSaveButtonTarget = false;
    state.saveButtonTarget = null;
    state.hasCloseButtonTarget = false;
    state.closeButtonTarget = null;
    state.hasLoadingTarget = false;
    state.loadingTarget = null;
    state.hasErrorTarget = false;
    state.errorTarget = null;
    state.hasSuccessTarget = false;
    state.successTarget = null;
    state.hasChatAreaTarget = false;
    state.chatAreaTarget = null;

    // Private state
    state.currentPath = "";
    state.csrfToken = "";

    // Apply overrides
    Object.assign(state, overrides);

    return controller;
};

const createFullController = (): {
    controller: HtmlEditorController;
    elements: {
        container: HTMLElement;
        textarea: HTMLTextAreaElement;
        pageNameDisplay: HTMLElement;
        saveButton: HTMLButtonElement;
        closeButton: HTMLButtonElement;
        loading: HTMLElement;
        error: HTMLElement;
        success: HTMLElement;
        chatArea: HTMLElement;
    };
} => {
    // Create DOM elements
    const container = document.createElement("div");
    container.classList.add("grid-rows-[0fr]");

    const textarea = document.createElement("textarea");
    const pageNameDisplay = document.createElement("span");
    const saveButton = document.createElement("button");
    saveButton.textContent = defaultTranslations.saveChanges;

    const closeButton = document.createElement("button");
    const loading = document.createElement("div");
    loading.classList.add("hidden");

    const error = document.createElement("div");
    error.classList.add("hidden");

    const success = document.createElement("div");
    success.classList.add("hidden");

    const chatArea = document.createElement("div");
    chatArea.classList.add("grid-rows-[1fr]");

    const controller = createController({
        hasContainerTarget: true,
        containerTarget: container,
        hasTextareaTarget: true,
        textareaTarget: textarea,
        hasPageNameDisplayTarget: true,
        pageNameDisplayTarget: pageNameDisplay,
        hasSaveButtonTarget: true,
        saveButtonTarget: saveButton,
        hasCloseButtonTarget: true,
        closeButtonTarget: closeButton,
        hasLoadingTarget: true,
        loadingTarget: loading,
        hasErrorTarget: true,
        errorTarget: error,
        hasSuccessTarget: true,
        successTarget: success,
        hasChatAreaTarget: true,
        chatAreaTarget: chatArea,
    });

    return {
        controller,
        elements: {
            container,
            textarea,
            pageNameDisplay,
            saveButton,
            closeButton,
            loading,
            error,
            success,
            chatArea,
        },
    };
};

describe("HtmlEditorController", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe("connect", () => {
        it("should load CSRF token from DOM input", () => {
            const csrfInput = document.createElement("input");
            csrfInput.name = "_html_editor_csrf_token";
            csrfInput.value = "test-csrf-token-123";
            document.body.appendChild(csrfInput);

            const controller = createController();
            controller.connect();

            const state = controller as unknown as MockControllerState;
            expect(state.csrfToken).toBe("test-csrf-token-123");
        });

        it("should not fail when CSRF input is not present", () => {
            const controller = createController();

            expect(() => controller.connect()).not.toThrow();

            const state = controller as unknown as MockControllerState;
            expect(state.csrfToken).toBe("");
        });
    });

    describe("openEditor", () => {
        it("should do nothing when path is not provided", async () => {
            const { controller } = createFullController();
            const event = new CustomEvent<{ path: string }>("html-editor:open", {
                detail: { path: "" },
            });

            await controller.openEditor(event);

            const state = controller as unknown as MockControllerState;
            expect(state.currentPath).toBe("");
        });

        it("should set currentPath from event detail", async () => {
            const { controller } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ content: "<html></html>" }), { status: 200 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            const state = controller as unknown as MockControllerState;
            expect(state.currentPath).toBe("dist/index.html");
        });

        it("should show loading state and hide error/success", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ content: "<html></html>" }), { status: 200 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            // After completion, loading should be hidden again
            expect(elements.loading.classList.contains("hidden")).toBe(true);
            expect(elements.error.classList.contains("hidden")).toBe(true);
            expect(elements.success.classList.contains("hidden")).toBe(true);
        });

        it("should update page name display", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ content: "<html></html>" }), { status: 200 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/about.html" },
            });
            await controller.openEditor(event);

            expect(elements.pageNameDisplay.textContent).toBe("src/about.html");
        });

        it("should slide up chat area and slide down container", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ content: "<html></html>" }), { status: 200 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            expect(elements.chatArea.classList.contains("grid-rows-[0fr]")).toBe(true);
            expect(elements.chatArea.classList.contains("grid-rows-[1fr]")).toBe(false);
            expect(elements.container.classList.contains("grid-rows-[1fr]")).toBe(true);
            expect(elements.container.classList.contains("grid-rows-[0fr]")).toBe(false);
        });

        it("should fetch content and populate textarea", async () => {
            const { controller, elements } = createFullController();
            const mockContent = "<html><body>Hello World</body></html>";
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ content: mockContent }), { status: 200 }),
            );

            const state = controller as unknown as MockControllerState;
            state.loadUrlValue = "/workspace/ws-123/page-content";

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            expect(fetch).toHaveBeenCalledWith(
                "/workspace/ws-123/page-content?path=dist%2Findex.html",
                expect.objectContaining({
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                }),
            );
            expect(elements.textarea.value).toBe(mockContent);
        });

        it("should show error when response is not OK", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ error: "File not found" }), { status: 404 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/missing.html" },
            });
            await controller.openEditor(event);

            expect(elements.error.classList.contains("hidden")).toBe(false);
            expect(elements.error.textContent).toBe("File not found");
        });

        it("should show error when response contains error field", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ error: "Access denied" }), { status: 200 }),
            );

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            expect(elements.error.classList.contains("hidden")).toBe(false);
            expect(elements.error.textContent).toBe("Access denied");
        });

        it("should show translated error on network failure", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("Network error"));

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            expect(elements.error.classList.contains("hidden")).toBe(false);
            expect(elements.error.textContent).toBe("Network error");
        });

        it("should use translated loadError when error has no message", async () => {
            const { controller, elements } = createFullController();
            vi.spyOn(globalThis, "fetch").mockRejectedValue("Unknown error");

            const event = new CustomEvent("html-editor:open", {
                detail: { path: "dist/index.html" },
            });
            await controller.openEditor(event);

            expect(elements.error.textContent).toBe(defaultTranslations.loadError);
        });
    });

    describe("saveChanges", () => {
        it("should do nothing without currentPath", async () => {
            const { controller } = createFullController();
            const fetchSpy = vi.spyOn(globalThis, "fetch");

            await controller.saveChanges();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("should do nothing without textarea target", async () => {
            const controller = createController({
                hasTextareaTarget: false,
            });
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            const fetchSpy = vi.spyOn(globalThis, "fetch");

            await controller.saveChanges();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("should send FormData with path, content and CSRF token", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";
            state.csrfToken = "csrf-token-456";
            state.saveUrlValue = "/workspace/ws-123/save-page";
            elements.textarea.value = "<html>Updated content</html>";

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ success: true }), { status: 200 }),
            );

            await controller.saveChanges();

            expect(fetch).toHaveBeenCalledWith(
                "/workspace/ws-123/save-page",
                expect.objectContaining({
                    method: "POST",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                }),
            );

            // Verify FormData contents
            const callArgs = vi.mocked(fetch).mock.calls[0];
            const formData = callArgs[1]?.body as FormData;
            expect(formData.get("path")).toBe("dist/index.html");
            expect(formData.get("content")).toBe("<html>Updated content</html>");
            expect(formData.get("_csrf_token")).toBe("csrf-token-456");
        });

        it("should disable buttons and show saving state during request", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            let resolvePromise: (value: Response) => void;
            const pendingPromise = new Promise<Response>((resolve) => {
                resolvePromise = resolve;
            });
            vi.spyOn(globalThis, "fetch").mockReturnValue(pendingPromise);

            const savePromise = controller.saveChanges();

            // During saving
            expect(elements.saveButton.disabled).toBe(true);
            expect(elements.closeButton.disabled).toBe(true);
            expect(elements.saveButton.textContent).toBe(defaultTranslations.saving);

            // Resolve the promise
            resolvePromise!(new Response(JSON.stringify({ success: true }), { status: 200 }));
            await savePromise;

            // After saving
            expect(elements.saveButton.disabled).toBe(false);
            expect(elements.closeButton.disabled).toBe(false);
            expect(elements.saveButton.textContent).toBe(defaultTranslations.saveChanges);
        });

        it("should show success message on successful save", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ success: true }), { status: 200 }),
            );

            await controller.saveChanges();

            expect(elements.success.classList.contains("hidden")).toBe(false);
            expect(elements.success.textContent).toBe(defaultTranslations.saveSuccess);
            expect(elements.error.classList.contains("hidden")).toBe(true);
        });

        it("should show error message when save fails with error response", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ error: "Permission denied" }), { status: 403 }),
            );

            await controller.saveChanges();

            expect(elements.error.classList.contains("hidden")).toBe(false);
            expect(elements.error.textContent).toBe("Permission denied");
            expect(elements.success.classList.contains("hidden")).toBe(true);
        });

        it("should show error message on network failure", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("Connection lost"));

            await controller.saveChanges();

            expect(elements.error.classList.contains("hidden")).toBe(false);
            expect(elements.error.textContent).toBe("Connection lost");
        });

        it("should use translated saveError when error has no message", async () => {
            const { controller, elements } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            vi.spyOn(globalThis, "fetch").mockRejectedValue("Unknown error");

            await controller.saveChanges();

            expect(elements.error.textContent).toBe(defaultTranslations.saveError);
        });
    });

    describe("closeEditor", () => {
        it("should reset currentPath", () => {
            const { controller } = createFullController();
            const state = controller as unknown as MockControllerState;
            state.currentPath = "dist/index.html";

            controller.closeEditor();

            expect(state.currentPath).toBe("");
        });

        it("should clear textarea value", () => {
            const { controller, elements } = createFullController();
            elements.textarea.value = "<html>Some content</html>";

            controller.closeEditor();

            expect(elements.textarea.value).toBe("");
        });

        it("should hide error and success messages", () => {
            const { controller, elements } = createFullController();
            elements.error.classList.remove("hidden");
            elements.success.classList.remove("hidden");

            controller.closeEditor();

            expect(elements.error.classList.contains("hidden")).toBe(true);
            expect(elements.success.classList.contains("hidden")).toBe(true);
        });

        it("should slide up container and slide down chat area", () => {
            const { controller, elements } = createFullController();
            // Simulate open state
            elements.container.classList.remove("grid-rows-[0fr]");
            elements.container.classList.add("grid-rows-[1fr]");
            elements.chatArea.classList.remove("grid-rows-[1fr]");
            elements.chatArea.classList.add("grid-rows-[0fr]");

            controller.closeEditor();

            expect(elements.container.classList.contains("grid-rows-[0fr]")).toBe(true);
            expect(elements.container.classList.contains("grid-rows-[1fr]")).toBe(false);
            expect(elements.chatArea.classList.contains("grid-rows-[1fr]")).toBe(true);
            expect(elements.chatArea.classList.contains("grid-rows-[0fr]")).toBe(false);
        });

        it("should not fail without targets", () => {
            const controller = createController();

            expect(() => controller.closeEditor()).not.toThrow();
        });
    });

    describe("helper methods (indirectly tested)", () => {
        it("showLoading should hide textarea and show loading element", async () => {
            const { controller, elements } = createFullController();

            // We can test this through openEditor which calls showLoading
            let resolvePromise: (value: Response) => void;
            const pendingPromise = new Promise<Response>((resolve) => {
                resolvePromise = resolve;
            });
            vi.spyOn(globalThis, "fetch").mockReturnValue(pendingPromise);

            const openPromise = controller.openEditor(
                new CustomEvent("html-editor:open", { detail: { path: "test.html" } }),
            );

            // During loading
            expect(elements.loading.classList.contains("hidden")).toBe(false);
            expect(elements.textarea.classList.contains("hidden")).toBe(true);

            resolvePromise!(new Response(JSON.stringify({ content: "" }), { status: 200 }));
            await openPromise;

            // After loading
            expect(elements.loading.classList.contains("hidden")).toBe(true);
            expect(elements.textarea.classList.contains("hidden")).toBe(false);
        });
    });
});
