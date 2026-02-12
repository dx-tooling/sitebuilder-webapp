import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import PhotoImageController from "../../../../src/PhotoBuilder/Presentation/Resources/assets/controllers/photo_image_controller.ts";

/**
 * Unit tests for the PhotoImage Stimulus controller.
 * Tests image state updates, prompt editing, event dispatching, and button state management.
 */

interface MockControllerState {
    positionValue: number;
    hasMediaStoreValue: boolean;
    generatingPromptTextValue: string;
    imageTarget: HTMLImageElement;
    placeholderTarget: HTMLElement;
    promptTextareaTarget: HTMLTextAreaElement;
    keepCheckboxTarget: HTMLInputElement;
    regenerateButtonTarget: HTMLButtonElement;
    hasUploadButtonTarget: boolean;
    uploadButtonTarget: HTMLButtonElement;
    statusBadgeTarget: HTMLElement;
    hasFileNameLabelTarget: boolean;
    fileNameLabelTarget: HTMLElement;
    imageId: string | null;
    currentStatus: string;
    suggestedFileName: string | null;
    promptAwaitingRegenerate: boolean;
    promptBeforeRegenerate: string | null;
}

interface ImageStateDetail {
    id: string;
    position: number;
    prompt: string | null;
    suggestedFileName: string | null;
    status: string;
    imageUrl: string | null;
    errorMessage: string | null;
}

const createController = (
    overrides: Partial<MockControllerState> = {},
): {
    controller: PhotoImageController;
    elements: {
        controllerElement: HTMLElement;
        image: HTMLImageElement;
        placeholder: HTMLElement;
        promptTextarea: HTMLTextAreaElement;
        keepCheckbox: HTMLInputElement;
        regenerateButton: HTMLButtonElement;
        uploadButton: HTMLButtonElement;
        statusBadge: HTMLElement;
        fileNameLabel: HTMLElement;
    };
} => {
    const controllerElement = document.createElement("div");
    const image = document.createElement("img");
    image.classList.add("hidden");
    const placeholder = document.createElement("div");
    const promptTextarea = document.createElement("textarea");
    const keepCheckbox = document.createElement("input");
    keepCheckbox.type = "checkbox";
    const regenerateButton = document.createElement("button");
    const uploadButton = document.createElement("button");
    const statusBadge = document.createElement("span");
    statusBadge.classList.add("hidden");
    const fileNameLabel = document.createElement("span");

    controllerElement.appendChild(image);
    controllerElement.appendChild(placeholder);
    controllerElement.appendChild(promptTextarea);
    controllerElement.appendChild(keepCheckbox);
    controllerElement.appendChild(regenerateButton);
    controllerElement.appendChild(uploadButton);
    controllerElement.appendChild(statusBadge);
    controllerElement.appendChild(fileNameLabel);

    const controller = Object.create(PhotoImageController.prototype) as PhotoImageController;
    const state = controller as unknown as MockControllerState;

    state.positionValue = 0;
    state.hasMediaStoreValue = false;
    state.generatingPromptTextValue = "Generating...";
    state.promptAwaitingRegenerate = false;
    state.promptBeforeRegenerate = null;
    state.imageTarget = image;
    state.placeholderTarget = placeholder;
    state.promptTextareaTarget = promptTextarea;
    state.keepCheckboxTarget = keepCheckbox;
    state.regenerateButtonTarget = regenerateButton;
    state.hasUploadButtonTarget = true;
    state.uploadButtonTarget = uploadButton;
    state.statusBadgeTarget = statusBadge;
    state.hasFileNameLabelTarget = true;
    state.fileNameLabelTarget = fileNameLabel;
    state.imageId = null;
    state.currentStatus = "pending";
    state.suggestedFileName = null;

    Object.assign(state, overrides);

    Object.defineProperty(controller, "element", {
        get: () => controllerElement,
        configurable: true,
    });

    // Mock dispatch to capture events
    (controller as unknown as { dispatch: (name: string, options: object) => void }).dispatch = vi.fn(
        (name: string, options: { detail: object }) => {
            controllerElement.dispatchEvent(
                new CustomEvent(`photo-image:${name}`, {
                    detail: options.detail,
                    bubbles: true,
                }),
            );
        },
    );

    return {
        controller,
        elements: {
            controllerElement,
            image,
            placeholder,
            promptTextarea,
            keepCheckbox,
            regenerateButton,
            uploadButton,
            statusBadge,
            fileNameLabel,
        },
    };
};

const makeStateEvent = (data: ImageStateDetail): CustomEvent<ImageStateDetail> => {
    return new CustomEvent("photo-builder:stateChanged", { detail: data });
};

describe("PhotoImageController", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe("updateFromState", () => {
        it("should set imageId and currentStatus from event data", () => {
            const { controller } = createController();
            const state = controller as unknown as MockControllerState;

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "A sunset",
                    suggestedFileName: "sunset.jpg",
                    status: "completed",
                    imageUrl: "/images/img-1/file",
                    errorMessage: null,
                }),
            );

            expect(state.imageId).toBe("img-1");
            expect(state.currentStatus).toBe("completed");
            expect(state.suggestedFileName).toBe("sunset.jpg");
        });

        it("should store imageId as data attribute on the element", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-42",
                    position: 0,
                    prompt: null,
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.controllerElement.getAttribute("data-photo-image-image-id")).toBe("img-42");
        });

        it("should update prompt textarea with prompt from event", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "A professional office photo",
                    suggestedFileName: "office.jpg",
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.promptTextarea.value).toBe("A professional office photo");
        });

        it("should not update prompt textarea when it is focused", () => {
            const { controller, elements } = createController();
            document.body.appendChild(elements.controllerElement);
            elements.promptTextarea.value = "User edited prompt";
            elements.promptTextarea.focus();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "Server prompt",
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.promptTextarea.value).toBe("User edited prompt");
        });

        it("should not update prompt textarea when prompt is null", () => {
            const { controller, elements } = createController();
            elements.promptTextarea.value = "Existing prompt";

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: null,
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.promptTextarea.value).toBe("Existing prompt");
        });
    });

    describe("image display", () => {
        it("should show image and hide placeholder when completed with imageUrl", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "test.jpg",
                    status: "completed",
                    imageUrl: "/api/photo-builder/images/img-1/file",
                    errorMessage: null,
                }),
            );

            expect(elements.image.src).toContain("/api/photo-builder/images/img-1/file");
            expect(elements.image.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.classList.contains("hidden")).toBe(true);
        });

        it("should not change img.src when updateFromState is called again with same imageUrl", () => {
            const { controller, elements } = createController();
            const imageUrl = "/api/photo-builder/images/img-1/file";

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "test.jpg",
                    status: "completed",
                    imageUrl,
                    errorMessage: null,
                }),
            );
            const srcAfterFirst = elements.image.src;

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "test.jpg",
                    status: "completed",
                    imageUrl,
                    errorMessage: null,
                }),
            );

            expect(elements.image.src).toBe(srcAfterFirst);
        });

        it("should hide image and show placeholder when generating", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
        });

        it("should hide image and show placeholder when pending", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
        });

        it("should show error message in placeholder when failed", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Rate limit exceeded",
                }),
            );

            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.innerHTML).toContain("Rate limit exceeded");
        });

        it("should show default error message when failed without errorMessage", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.placeholder.innerHTML).toContain("Generation failed");
        });

        it("should restore pulsing placeholder when status changes from failed to generating", () => {
            const { controller, elements } = createController();

            // First: image fails
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Rate limit exceeded",
                }),
            );
            expect(elements.placeholder.innerHTML).toContain("Rate limit exceeded");

            // Then: regeneration starts
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            // Placeholder should show pulsing animation, NOT the error message
            expect(elements.placeholder.innerHTML).not.toContain("Rate limit exceeded");
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
        });

        it("should restore pulsing placeholder when status changes from failed to pending", () => {
            const { controller, elements } = createController();

            // First: image fails
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Timeout",
                }),
            );
            expect(elements.placeholder.innerHTML).toContain("Timeout");

            // Then: status goes to pending
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.placeholder.innerHTML).not.toContain("Timeout");
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
        });
    });

    describe("status badge", () => {
        it("should show Done badge when completed", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            expect(elements.statusBadge.textContent).toBe("Done");
            expect(elements.statusBadge.classList.contains("hidden")).toBe(false);
        });

        it("should show Generating badge when generating", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.statusBadge.textContent).toBe("Generating...");
            expect(elements.statusBadge.classList.contains("hidden")).toBe(false);
        });

        it("should show Failed badge when failed", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Error",
                }),
            );

            expect(elements.statusBadge.textContent).toBe("Failed");
            expect(elements.statusBadge.classList.contains("hidden")).toBe(false);
        });

        it("should hide badge for pending status", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.statusBadge.classList.contains("hidden")).toBe(true);
        });
    });

    describe("button states", () => {
        it("should disable regenerate button when parent is generating", () => {
            const { controller, elements } = createController();
            const parentDiv = document.createElement("div");
            parentDiv.setAttribute("data-photo-builder-generating", "true");
            parentDiv.appendChild(elements.controllerElement);
            document.body.appendChild(parentDiv);

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            expect(elements.regenerateButton.disabled).toBe(true);
        });

        it("should enable regenerate button when parent is not generating and status is not generating", () => {
            const { controller, elements } = createController();
            const parentDiv = document.createElement("div");
            parentDiv.setAttribute("data-photo-builder-generating", "false");
            parentDiv.appendChild(elements.controllerElement);
            document.body.appendChild(parentDiv);

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            expect(elements.regenerateButton.disabled).toBe(false);
        });

        it("should disable upload button when status is not completed", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.uploadButton.disabled).toBe(true);
        });

        it("should enable upload button when status is completed and no generation in progress", () => {
            const { controller, elements } = createController();
            const parentDiv = document.createElement("div");
            parentDiv.setAttribute("data-photo-builder-generating", "false");
            parentDiv.appendChild(elements.controllerElement);
            document.body.appendChild(parentDiv);

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            expect(elements.uploadButton.disabled).toBe(false);
        });
    });

    describe("clearPromptIfNotKept and prompt regeneration", () => {
        it("should apply new prompt when status is pending after clearPromptIfNotKept", () => {
            const { controller, elements } = createController();
            elements.promptTextarea.value = "Old prompt";

            controller.clearPromptIfNotKept();
            expect(elements.promptTextarea.value).toBe("Generating...");

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "New prompt from AI",
                    suggestedFileName: null,
                    status: "pending",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );

            expect(elements.promptTextarea.value).toBe("New prompt from AI");
        });

        it("should apply new prompt even when status is already completed (fast generation)", () => {
            const { controller, elements } = createController();
            elements.promptTextarea.value = "Old prompt";

            controller.clearPromptIfNotKept();
            expect(elements.promptTextarea.value).toBe("Generating...");

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "New prompt from AI",
                    suggestedFileName: "img.jpg",
                    status: "completed",
                    imageUrl: "/img-1/file",
                    errorMessage: null,
                }),
            );

            expect(elements.promptTextarea.value).toBe("New prompt from AI");
            expect(elements.promptTextarea.classList.contains("animate-pulse")).toBe(false);
        });

        it("should not apply old prompt while awaiting regeneration", () => {
            const { controller, elements } = createController();
            elements.promptTextarea.value = "Old prompt";

            controller.clearPromptIfNotKept();
            expect(elements.promptTextarea.value).toBe("Generating...");

            // Poll returns old data (backend hasn't processed regeneration yet)
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "Old prompt",
                    suggestedFileName: null,
                    status: "completed",
                    imageUrl: "/img-1/file",
                    errorMessage: null,
                }),
            );

            // Should stay at "Generating..." because prompt hasn't changed
            expect(elements.promptTextarea.value).toBe("Generating...");
            expect(elements.promptTextarea.classList.contains("animate-pulse")).toBe(true);
        });
    });

    describe("onPromptInput", () => {
        it("should auto-check the keep checkbox", () => {
            const { controller, elements } = createController();
            elements.keepCheckbox.checked = false;

            controller.onPromptInput();

            expect(elements.keepCheckbox.checked).toBe(true);
        });

        it("should dispatch promptEdited event with position and prompt", () => {
            const { controller, elements } = createController({ positionValue: 2 });
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-5";
            elements.promptTextarea.value = "Updated prompt text";

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:promptEdited", eventHandler);

            controller.onPromptInput();

            expect(eventHandler).toHaveBeenCalled();
            const event = eventHandler.mock.calls[0][0] as CustomEvent;
            expect(event.detail.position).toBe(2);
            expect(event.detail.imageId).toBe("img-5");
            expect(event.detail.prompt).toBe("Updated prompt text");
        });
    });

    describe("requestRegenerate", () => {
        it("should dispatch regenerateRequested event when imageId is set", () => {
            const { controller, elements } = createController({ positionValue: 1 });
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-3";
            elements.promptTextarea.value = "A mountain landscape";

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:regenerateRequested", eventHandler);

            controller.requestRegenerate();

            expect(eventHandler).toHaveBeenCalled();
            const event = eventHandler.mock.calls[0][0] as CustomEvent;
            expect(event.detail.position).toBe(1);
            expect(event.detail.imageId).toBe("img-3");
            expect(event.detail.prompt).toBe("A mountain landscape");
        });

        it("should not dispatch event when imageId is null", () => {
            const { controller, elements } = createController();

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:regenerateRequested", eventHandler);

            controller.requestRegenerate();

            expect(eventHandler).not.toHaveBeenCalled();
        });

        it("should immediately show generating placeholder when regenerating a completed image", () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-1";

            // First: image is completed and visible
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "test.jpg",
                    status: "completed",
                    imageUrl: "/api/photo-builder/images/img-1/file",
                    errorMessage: null,
                }),
            );
            expect(elements.image.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.classList.contains("hidden")).toBe(true);

            // User clicks regenerate
            controller.requestRegenerate();

            // Should immediately show generating placeholder
            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
            expect(elements.statusBadge.textContent).toBe("Generating...");
        });

        it("should immediately clear error and show generating placeholder when regenerating a failed image", () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-1";

            // First: image has failed
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Rate limit exceeded",
                }),
            );
            expect(elements.placeholder.innerHTML).toContain("Rate limit exceeded");

            // User clicks regenerate
            controller.requestRegenerate();

            // Should immediately show generating placeholder, NOT the error
            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.innerHTML).not.toContain("Rate limit exceeded");
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
            expect(elements.statusBadge.textContent).toBe("Generating...");
        });
    });

    describe("resetToGeneratingPlaceholder", () => {
        it("should reset image card to generating state", () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-1";

            // Start with a completed image
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: "test.jpg",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            controller.resetToGeneratingPlaceholder();

            expect(elements.image.classList.contains("hidden")).toBe(true);
            expect(elements.placeholder.classList.contains("hidden")).toBe(false);
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
            expect(elements.statusBadge.textContent).toBe("Generating...");
            expect(state.currentStatus).toBe("generating");
        });

        it("should clear error HTML from placeholder", () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-1";

            // Start with a failed image
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "test",
                    suggestedFileName: null,
                    status: "failed",
                    imageUrl: null,
                    errorMessage: "Server error",
                }),
            );
            expect(elements.placeholder.innerHTML).toContain("Server error");

            controller.resetToGeneratingPlaceholder();

            expect(elements.placeholder.innerHTML).not.toContain("Server error");
            expect(elements.placeholder.innerHTML).toContain("animate-pulse");
        });
    });

    describe("requestUpload", () => {
        it("should dispatch uploadRequested event with suggestedFileName", () => {
            const { controller, elements } = createController({ positionValue: 3 });
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-7";
            state.suggestedFileName = "cozy-cafe.jpg";

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:uploadRequested", eventHandler);

            controller.requestUpload();

            expect(eventHandler).toHaveBeenCalled();
            const event = eventHandler.mock.calls[0][0] as CustomEvent;
            expect(event.detail.position).toBe(3);
            expect(event.detail.imageId).toBe("img-7");
            expect(event.detail.suggestedFileName).toBe("cozy-cafe.jpg");
        });

        it("should use empty string when suggestedFileName is null", () => {
            const { controller, elements } = createController();
            const state = controller as unknown as MockControllerState;
            state.imageId = "img-8";
            state.suggestedFileName = null;

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:uploadRequested", eventHandler);

            controller.requestUpload();

            expect(eventHandler).toHaveBeenCalled();
            const event = eventHandler.mock.calls[0][0] as CustomEvent;
            expect(event.detail.suggestedFileName).toBe("");
        });

        it("should not dispatch event when imageId is null", () => {
            const { controller, elements } = createController();

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("photo-image:uploadRequested", eventHandler);

            controller.requestUpload();

            expect(eventHandler).not.toHaveBeenCalled();
        });
    });

    describe("fileNameLabel", () => {
        it("should display suggestedFileName from state update", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "A sunset",
                    suggestedFileName: "a-modern-office.png",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );

            expect(elements.fileNameLabel.textContent).toBe("a-modern-office.png");
        });

        it("should clear label when suggestedFileName is null", () => {
            const { controller, elements } = createController();

            // First set a filename
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "A sunset",
                    suggestedFileName: "sunset.png",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );
            expect(elements.fileNameLabel.textContent).toBe("sunset.png");

            // Then clear it
            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "A sunset",
                    suggestedFileName: null,
                    status: "generating",
                    imageUrl: null,
                    errorMessage: null,
                }),
            );
            expect(elements.fileNameLabel.textContent).toBe("");
        });

        it("should update label when suggestedFileName changes", () => {
            const { controller, elements } = createController();

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "First prompt",
                    suggestedFileName: "first-prompt.png",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );
            expect(elements.fileNameLabel.textContent).toBe("first-prompt.png");

            controller.updateFromState(
                makeStateEvent({
                    id: "img-1",
                    position: 0,
                    prompt: "Second prompt",
                    suggestedFileName: "second-prompt.png",
                    status: "completed",
                    imageUrl: "/file",
                    errorMessage: null,
                }),
            );
            expect(elements.fileNameLabel.textContent).toBe("second-prompt.png");
        });

        it("should not throw when fileNameLabel target is absent", () => {
            const { controller } = createController({ hasFileNameLabelTarget: false });

            expect(() => {
                controller.updateFromState(
                    makeStateEvent({
                        id: "img-1",
                        position: 0,
                        prompt: "test",
                        suggestedFileName: "test.png",
                        status: "completed",
                        imageUrl: "/file",
                        errorMessage: null,
                    }),
                );
            }).not.toThrow();
        });
    });
});
