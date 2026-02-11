import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import PhotoBuilderController from "../../../../src/PhotoBuilder/Presentation/Resources/assets/controllers/photo_builder_controller.ts";

/**
 * Unit tests for the PhotoBuilder Stimulus controller (orchestrator).
 * Tests session creation, polling, state management, and event handling.
 */

interface SessionResponse {
    sessionId?: string;
    status: string;
    userPrompt?: string;
    images?: ImageData[];
    error?: string;
}

interface ImageData {
    id: string;
    position: number;
    prompt: string | null;
    suggestedFileName: string | null;
    status: string;
    imageUrl: string | null;
    errorMessage: string | null;
    uploadedToMediaStore?: boolean;
    uploadedFileName?: string | null;
}

interface MockControllerState {
    createSessionUrlValue: string;
    pollUrlPatternValue: string;
    regeneratePromptsUrlPatternValue: string;
    regenerateImageUrlPatternValue: string;
    updatePromptUrlPatternValue: string;
    uploadToMediaStoreUrlPatternValue: string;
    csrfTokenValue: string;
    workspaceIdValue: string;
    pagePathValue: string;
    conversationIdValue: string;
    imageCountValue: number;
    defaultUserPromptValue: string;
    editorUrlValue: string;
    hasRemoteAssetsValue: boolean;
    loadingOverlayTarget: HTMLElement;
    mainContentTarget: HTMLElement;
    userPromptTarget: HTMLTextAreaElement;
    regeneratePromptsButtonTarget: HTMLButtonElement;
    hasEmbedButtonTarget: boolean;
    embedButtonTarget: HTMLButtonElement;
    imageCardTargets: HTMLElement[];
    hasUploadingImagesOverlayTarget: boolean;
    uploadingImagesOverlayTarget: HTMLElement;
    sessionId: string | null;
    pollingTimeoutId: ReturnType<typeof setTimeout> | null;
    isActive: boolean;
    anyGenerating: boolean;
    lastImages: ImageData[];
}

const createController = (
    overrides: Partial<MockControllerState> = {},
): {
    controller: PhotoBuilderController;
    elements: {
        controllerElement: HTMLElement;
        loadingOverlay: HTMLElement;
        mainContent: HTMLElement;
        userPrompt: HTMLTextAreaElement;
        regeneratePromptsButton: HTMLButtonElement;
        embedButton: HTMLButtonElement;
        imageCards: HTMLElement[];
    };
} => {
    const controllerElement = document.createElement("div");
    const loadingOverlay = document.createElement("div");
    const mainContent = document.createElement("div");
    mainContent.classList.add("hidden");
    const userPrompt = document.createElement("textarea");
    const regeneratePromptsButton = document.createElement("button");
    const embedButton = document.createElement("button");

    // Create image cards
    const imageCards: HTMLElement[] = [];
    for (let i = 0; i < 5; i++) {
        const card = document.createElement("div");
        controllerElement.appendChild(card);
        imageCards.push(card);
    }

    controllerElement.appendChild(loadingOverlay);
    controllerElement.appendChild(mainContent);
    controllerElement.appendChild(userPrompt);
    controllerElement.appendChild(regeneratePromptsButton);
    controllerElement.appendChild(embedButton);

    const controller = Object.create(PhotoBuilderController.prototype) as PhotoBuilderController;
    const state = controller as unknown as MockControllerState;

    state.createSessionUrlValue = "/api/photo-builder/sessions";
    state.pollUrlPatternValue = "/api/photo-builder/sessions/00000000-0000-0000-0000-000000000000";
    state.regeneratePromptsUrlPatternValue =
        "/api/photo-builder/sessions/00000000-0000-0000-0000-000000000000/regenerate-prompts";
    state.regenerateImageUrlPatternValue = "/api/photo-builder/images/00000000-0000-0000-0000-111111111111/regenerate";
    state.updatePromptUrlPatternValue = "/api/photo-builder/images/00000000-0000-0000-0000-111111111111/update-prompt";
    state.uploadToMediaStoreUrlPatternValue =
        "/api/photo-builder/images/00000000-0000-0000-0000-111111111111/upload-to-media-store";
    state.csrfTokenValue = "test-csrf-token";
    state.workspaceIdValue = "ws-123";
    state.pagePathValue = "index.html";
    state.conversationIdValue = "conv-456";
    state.imageCountValue = 5;
    state.defaultUserPromptValue = "The generated images should convey professionalism.";
    state.editorUrlValue = "/conversation/conv-456";
    state.hasRemoteAssetsValue = false;

    state.loadingOverlayTarget = loadingOverlay;
    state.mainContentTarget = mainContent;
    state.userPromptTarget = userPrompt;
    state.regeneratePromptsButtonTarget = regeneratePromptsButton;
    state.hasEmbedButtonTarget = true;
    state.embedButtonTarget = embedButton;
    state.imageCardTargets = imageCards;
    state.hasUploadingImagesOverlayTarget = false;
    state.uploadingImagesOverlayTarget = document.createElement("div");

    state.sessionId = null;
    state.pollingTimeoutId = null;
    state.isActive = false;
    state.anyGenerating = false;
    state.lastImages = [];

    Object.assign(state, overrides);

    Object.defineProperty(controller, "element", {
        get: () => controllerElement,
        configurable: true,
    });

    return {
        controller,
        elements: {
            controllerElement,
            loadingOverlay,
            mainContent,
            userPrompt,
            regeneratePromptsButton,
            embedButton,
            imageCards,
        },
    };
};

/**
 * Helper to run a single async cycle (lets fetch promises resolve).
 */
async function flushPromises(): Promise<void> {
    await vi.advanceTimersByTimeAsync(0);
}

describe("PhotoBuilderController", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        vi.useFakeTimers();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    describe("connect", () => {
        it("should create a session on connect", async () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
                new Response(JSON.stringify({ sessionId: "sess-1", status: "generating_prompts" }), { status: 200 }),
            );

            controller.connect();
            await flushPromises();

            expect(globalThis.fetch).toHaveBeenCalledWith(
                "/api/photo-builder/sessions",
                expect.objectContaining({
                    method: "POST",
                    headers: expect.objectContaining({
                        "Content-Type": "application/json",
                        "X-CSRF-Token": "test-csrf-token",
                    }),
                }),
            );

            const state = controller as unknown as MockControllerState;
            expect(state.sessionId).toBe("sess-1");

            controller.disconnect();
        });

        it("should send workspaceId, conversationId, pagePath, and userPrompt in session creation body", async () => {
            const { controller } = createController();

            let capturedBody = "";
            vi.spyOn(globalThis, "fetch").mockImplementation(async (_url, options) => {
                if (options && typeof options === "object" && "body" in options) {
                    capturedBody = options.body as string;
                }
                return new Response(JSON.stringify({ sessionId: "sess-1", status: "generating_prompts" }), {
                    status: 200,
                });
            });

            controller.connect();
            await flushPromises();

            const body = JSON.parse(capturedBody) as Record<string, string>;
            expect(body.workspaceId).toBe("ws-123");
            expect(body.conversationId).toBe("conv-456");
            expect(body.pagePath).toBe("index.html");
            expect(body.userPrompt).toBe("The generated images should convey professionalism.");

            controller.disconnect();
        });

        it("should set isActive to true on connect", () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValueOnce(
                new Response(JSON.stringify({ sessionId: "sess-1", status: "generating_prompts" }), { status: 200 }),
            );

            controller.connect();

            const state = controller as unknown as MockControllerState;
            expect(state.isActive).toBe(true);

            controller.disconnect();
        });
    });

    describe("disconnect", () => {
        it("should set isActive to false on disconnect", async () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ sessionId: "sess-1", status: "generating_prompts" }), { status: 200 }),
            );

            controller.connect();
            await flushPromises();

            controller.disconnect();

            const state = controller as unknown as MockControllerState;
            expect(state.isActive).toBe(false);
        });

        it("should clear polling timeout on disconnect", async () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ sessionId: "sess-1", status: "generating_prompts" }), { status: 200 }),
            );

            controller.connect();
            await flushPromises();

            controller.disconnect();

            expect(state.pollingTimeoutId).toBeNull();
        });
    });

    describe("poll response handling", () => {
        it("should show loading overlay during generating_prompts with no prompts", async () => {
            const { controller, elements } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "generating_prompts",
                userPrompt: "test",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: null,
                        suggestedFileName: null,
                        status: "pending",
                        imageUrl: null,
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises(); // session creation
            await flushPromises(); // first poll

            expect(elements.loadingOverlay.classList.contains("hidden")).toBe(false);
            expect(elements.mainContent.classList.contains("hidden")).toBe(true);

            controller.disconnect();
        });

        it("should hide loading overlay once prompts are ready", async () => {
            const { controller, elements } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "prompts_ready",
                userPrompt: "test",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "A sunset photo",
                        suggestedFileName: "sunset.jpg",
                        status: "pending",
                        imageUrl: null,
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises(); // session creation
            await flushPromises(); // first poll

            expect(elements.loadingOverlay.classList.contains("hidden")).toBe(true);
            expect(elements.mainContent.classList.contains("hidden")).toBe(false);

            controller.disconnect();
        });

        it("should set anyGenerating to true when status is generating_prompts", async () => {
            const { controller } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "generating_prompts",
                images: [],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises();
            await flushPromises();

            const state = controller as unknown as MockControllerState;
            expect(state.anyGenerating).toBe(true);

            controller.disconnect();
        });

        it("should set anyGenerating to false when all images are completed", async () => {
            const { controller } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "images_ready",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: "t.jpg",
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises();
            await flushPromises();

            const state = controller as unknown as MockControllerState;
            expect(state.anyGenerating).toBe(false);

            controller.disconnect();
        });

        it("should disable regenerate prompts button while generating", async () => {
            const { controller, elements } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "generating_images",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: null,
                        status: "generating",
                        imageUrl: null,
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises();
            await flushPromises();

            expect(elements.regeneratePromptsButton.disabled).toBe(true);

            controller.disconnect();
        });

        it("should enable regenerate prompts button when not generating", async () => {
            const { controller, elements } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "images_ready",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: null,
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises();
            await flushPromises();

            expect(elements.regeneratePromptsButton.disabled).toBe(false);

            controller.disconnect();
        });

        it("should dispatch stateChanged event on each image card", async () => {
            const { controller, elements } = createController();

            const images: ImageData[] = [
                {
                    id: "img-1",
                    position: 0,
                    prompt: "Prompt A",
                    suggestedFileName: "a.jpg",
                    status: "completed",
                    imageUrl: "/a",
                    errorMessage: null,
                },
                {
                    id: "img-2",
                    position: 1,
                    prompt: "Prompt B",
                    suggestedFileName: "b.jpg",
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                },
            ];

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "generating_images",
                images,
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            const card0Handler = vi.fn();
            const card1Handler = vi.fn();
            elements.imageCards[0].addEventListener("photo-builder:stateChanged", card0Handler);
            elements.imageCards[1].addEventListener("photo-builder:stateChanged", card1Handler);

            controller.connect();
            await flushPromises();
            await flushPromises();

            expect(card0Handler).toHaveBeenCalled();
            expect(card1Handler).toHaveBeenCalled();

            const event0 = card0Handler.mock.calls[0][0] as CustomEvent;
            expect(event0.detail.id).toBe("img-1");
            expect(event0.detail.prompt).toBe("Prompt A");

            const event1 = card1Handler.mock.calls[0][0] as CustomEvent;
            expect(event1.detail.id).toBe("img-2");
            expect(event1.detail.status).toBe("generating");

            controller.disconnect();
        });

        it("should set data-photo-builder-generating attribute on element", async () => {
            const { controller, elements } = createController();

            const createResponse: SessionResponse = { sessionId: "sess-1", status: "generating_prompts" };
            const pollResponse: SessionResponse = {
                status: "generating_images",
                images: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: null,
                        status: "generating",
                        imageUrl: null,
                        errorMessage: null,
                    },
                ],
            };

            let fetchCallCount = 0;
            vi.spyOn(globalThis, "fetch").mockImplementation(async () => {
                fetchCallCount++;
                if (fetchCallCount === 1) {
                    return new Response(JSON.stringify(createResponse), { status: 200 });
                }
                return new Response(JSON.stringify(pollResponse), { status: 200 });
            });

            controller.connect();
            await flushPromises();
            await flushPromises();

            expect(elements.controllerElement.getAttribute("data-photo-builder-generating")).toBe("true");

            controller.disconnect();
        });
    });

    describe("regeneratePrompts", () => {
        it("should not send request when sessionId is null", async () => {
            const { controller } = createController();
            const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            await controller.regeneratePrompts();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("should not send request when anyGenerating is true", async () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;
            state.sessionId = "sess-1";
            state.anyGenerating = true;

            const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            await controller.regeneratePrompts();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("should send regenerate request with user prompt and kept image IDs", async () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.sessionId = "sess-1";
            state.anyGenerating = false;
            elements.userPrompt.value = "Updated user prompt";

            // Set up a card with a checked keep checkbox
            const keepCheckbox = document.createElement("input");
            keepCheckbox.type = "checkbox";
            keepCheckbox.checked = true;
            keepCheckbox.setAttribute("data-photo-image-target", "keepCheckbox");
            elements.imageCards[1].appendChild(keepCheckbox);
            elements.imageCards[1].setAttribute("data-photo-image-image-id", "img-2");

            let capturedUrl = "";
            let capturedBody = "";
            vi.spyOn(globalThis, "fetch").mockImplementation(async (url, options) => {
                capturedUrl = url as string;
                if (options && typeof options === "object" && "body" in options) {
                    capturedBody = options.body as string;
                }
                return new Response("ok");
            });

            await controller.regeneratePrompts();

            expect(capturedUrl).toBe("/api/photo-builder/sessions/sess-1/regenerate-prompts");
            const body = JSON.parse(capturedBody) as { userPrompt: string; keepImageIds: string[] };
            expect(body.userPrompt).toBe("Updated user prompt");
            expect(body.keepImageIds).toContain("img-2");
        });
    });

    describe("handlePromptEdited", () => {
        it("should send prompt update to backend", () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:promptEdited", {
                detail: {
                    position: 0,
                    imageId: "img-1",
                    prompt: "A beautiful landscape",
                },
            });

            controller.handlePromptEdited(event);

            expect(globalThis.fetch).toHaveBeenCalledWith(
                "/api/photo-builder/images/img-1/update-prompt",
                expect.objectContaining({
                    method: "POST",
                    body: JSON.stringify({ prompt: "A beautiful landscape" }),
                }),
            );
        });

        it("should not send request when imageId is missing", () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:promptEdited", {
                detail: {
                    position: 0,
                    imageId: "",
                    prompt: "test",
                },
            });

            controller.handlePromptEdited(event);

            expect(globalThis.fetch).not.toHaveBeenCalled();
        });
    });

    describe("handleRegenerateImage", () => {
        it("should send regenerate request for the image", async () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;
            state.anyGenerating = false;

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:regenerateRequested", {
                detail: {
                    position: 2,
                    imageId: "img-3",
                    prompt: "An office scene",
                },
            });

            await controller.handleRegenerateImage(event);

            expect(globalThis.fetch).toHaveBeenCalledWith(
                "/api/photo-builder/images/img-3/regenerate",
                expect.objectContaining({ method: "POST" }),
            );
        });

        it("should not send request when anyGenerating is true", async () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;
            state.anyGenerating = true;

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:regenerateRequested", {
                detail: { position: 0, imageId: "img-1", prompt: "test" },
            });

            await controller.handleRegenerateImage(event);

            expect(globalThis.fetch).not.toHaveBeenCalled();
        });
    });

    describe("handleUploadToMediaStore", () => {
        it("should send upload request for the image", async () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:uploadRequested", {
                detail: {
                    position: 0,
                    imageId: "img-1",
                    suggestedFileName: "sunset.jpg",
                },
            });

            await controller.handleUploadToMediaStore(event);

            expect(globalThis.fetch).toHaveBeenCalledWith(
                "/api/photo-builder/images/img-1/upload-to-media-store",
                expect.objectContaining({ method: "POST" }),
            );
        });

        it("should not send request when imageId is missing", async () => {
            const { controller } = createController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("ok"));

            const event = new CustomEvent("photo-image:uploadRequested", {
                detail: { position: 0, imageId: "", suggestedFileName: "" },
            });

            await controller.handleUploadToMediaStore(event);

            expect(globalThis.fetch).not.toHaveBeenCalled();
        });
    });

    describe("embedIntoPage", () => {
        it("should navigate to editor with prefilled message when all images already uploaded", async () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;
            state.lastImages = [
                {
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "office-scene.jpg",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                    uploadedToMediaStore: true,
                    uploadedFileName: "00fa0883ee6db2e2_office-scene.jpg",
                },
                {
                    id: "img-2",
                    position: 1,
                    prompt: "test",
                    suggestedFileName: "team-photo.jpg",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                    uploadedToMediaStore: true,
                    uploadedFileName: "abc123_team-photo.jpg",
                },
                {
                    id: "img-3",
                    position: 2,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Error",
                },
            ];

            // Mock window.location.href
            const hrefSetter = vi.fn();
            Object.defineProperty(window, "location", {
                value: { href: "" },
                writable: true,
            });
            Object.defineProperty(window.location, "href", {
                set: hrefSetter,
                get: () => "",
            });

            await controller.embedIntoPage();

            expect(hrefSetter).toHaveBeenCalled();
            const url = hrefSetter.mock.calls[0][0] as string;
            expect(url).toContain("/conversation/conv-456?prefill=");
            expect(url).toContain(encodeURIComponent("00fa0883ee6db2e2_office-scene.jpg"));
            expect(url).toContain(encodeURIComponent("abc123_team-photo.jpg"));
            expect(url).toContain(encodeURIComponent("index.html"));
        });

        it("should upload non-uploaded images then navigate with actual S3 filenames", async () => {
            const uploadingOverlay = document.createElement("div");
            uploadingOverlay.classList.add("hidden");

            const { controller } = createController({
                hasRemoteAssetsValue: true,
                hasUploadingImagesOverlayTarget: true,
                uploadingImagesOverlayTarget: uploadingOverlay,
                lastImages: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: "office.jpg",
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                        uploadedToMediaStore: false,
                    },
                    {
                        id: "img-2",
                        position: 1,
                        prompt: "test",
                        suggestedFileName: "team.jpg",
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                        uploadedToMediaStore: true,
                        uploadedFileName: "abc123_team.jpg",
                    },
                ],
            });

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(
                    JSON.stringify({
                        url: "https://s3.example/uploads/20260211/00fa0883ee6db2e2_office.jpg",
                        fileName: "office.jpg",
                        uploadedFileName: "00fa0883ee6db2e2_office.jpg",
                    }),
                ),
            );

            const hrefSetter = vi.fn();
            Object.defineProperty(window, "location", {
                value: { href: "" },
                writable: true,
            });
            Object.defineProperty(window.location, "href", {
                set: hrefSetter,
                get: () => "",
            });

            await controller.embedIntoPage();

            expect(globalThis.fetch).toHaveBeenCalledWith(
                "/api/photo-builder/images/img-1/upload-to-media-store",
                expect.objectContaining({ method: "POST" }),
            );
            expect(hrefSetter).toHaveBeenCalled();
            const url = hrefSetter.mock.calls[0][0] as string;
            expect(url).toContain("/conversation/conv-456?prefill=");
            expect(url).toContain(encodeURIComponent("00fa0883ee6db2e2_office.jpg"));
            expect(url).toContain(encodeURIComponent("abc123_team.jpg"));
        });

        it("should not navigate when upload fails", async () => {
            const uploadingOverlay = document.createElement("div");
            uploadingOverlay.classList.add("hidden");

            const { controller } = createController({
                hasRemoteAssetsValue: true,
                hasUploadingImagesOverlayTarget: true,
                uploadingImagesOverlayTarget: uploadingOverlay,
                lastImages: [
                    {
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: "office.jpg",
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                        uploadedToMediaStore: false,
                    },
                ],
            });

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response("error", { status: 500 }));

            const hrefSetter = vi.fn();
            Object.defineProperty(window, "location", {
                value: { href: "" },
                writable: true,
            });
            Object.defineProperty(window.location, "href", {
                set: hrefSetter,
                get: () => "",
            });

            await controller.embedIntoPage();

            expect(hrefSetter).not.toHaveBeenCalled();
        });
    });
});
