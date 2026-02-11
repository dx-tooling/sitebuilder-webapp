import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import ChatBasedContentEditorController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";

describe("ChatBasedContentEditorController handleSuggestionInsert", () => {
    const createControllerWithInstructionTarget = (): {
        controller: ChatBasedContentEditorController;
        textarea: HTMLTextAreaElement;
    } => {
        const textarea = document.createElement("textarea");
        textarea.id = "instruction";
        document.body.appendChild(textarea);

        const controller = Object.create(
            ChatBasedContentEditorController.prototype,
        ) as ChatBasedContentEditorController;
        const state = controller as unknown as {
            hasInstructionTarget: boolean;
            instructionTarget: HTMLTextAreaElement;
        };
        state.hasInstructionTarget = true;
        state.instructionTarget = textarea;

        return { controller, textarea };
    };

    it("inserts suggestion text at cursor position in empty textarea", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "";
        textarea.selectionStart = 0;
        textarea.selectionEnd = 0;

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "Create a landing page" },
        });
        controller.handleSuggestionInsert(event);

        expect(textarea.value).toBe("Create a landing page");
        expect(textarea.selectionStart).toBe(21);
        expect(textarea.selectionEnd).toBe(21);
    });

    it("inserts suggestion text at cursor position in middle of existing text", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Please  for the homepage";
        textarea.selectionStart = 7;
        textarea.selectionEnd = 7;

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "add a hero section" },
        });
        controller.handleSuggestionInsert(event);

        expect(textarea.value).toBe("Please add a hero section for the homepage");
        expect(textarea.selectionStart).toBe(25);
        expect(textarea.selectionEnd).toBe(25);
    });

    it("replaces selected text with suggestion", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Please REPLACE_THIS for the homepage";
        textarea.selectionStart = 7;
        textarea.selectionEnd = 19;

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "add images" },
        });
        controller.handleSuggestionInsert(event);

        expect(textarea.value).toBe("Please add images for the homepage");
    });

    it("appends suggestion at end when cursor is at end", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Add a section about ";
        textarea.selectionStart = 20;
        textarea.selectionEnd = 20;

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "our team" },
        });
        controller.handleSuggestionInsert(event);

        expect(textarea.value).toBe("Add a section about our team");
    });

    it("does nothing when text is empty", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Original text";

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "" },
        });
        controller.handleSuggestionInsert(event);

        expect(textarea.value).toBe("Original text");
    });

    it("does nothing when detail is missing", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Original text";

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: null,
        });
        controller.handleSuggestionInsert(event as CustomEvent<{ text: string }>);

        expect(textarea.value).toBe("Original text");
    });

    it("does nothing without instruction target", () => {
        const controller = Object.create(
            ChatBasedContentEditorController.prototype,
        ) as ChatBasedContentEditorController;
        const state = controller as unknown as { hasInstructionTarget: boolean };
        state.hasInstructionTarget = false;

        const event = new CustomEvent("prompt-suggestions:insert", {
            detail: { text: "Some suggestion" },
        });

        // Should not throw
        expect(() => controller.handleSuggestionInsert(event)).not.toThrow();
    });
});

describe("ChatBasedContentEditorController handleUploadComplete", () => {
    const createControllerWithInstructionTarget = (): {
        controller: ChatBasedContentEditorController;
        textarea: HTMLTextAreaElement;
    } => {
        const textarea = document.createElement("textarea");
        textarea.id = "instruction";

        const controller = Object.create(
            ChatBasedContentEditorController.prototype,
        ) as ChatBasedContentEditorController;
        const state = controller as unknown as {
            hasInstructionTarget: boolean;
            instructionTarget: HTMLTextAreaElement;
        };
        state.hasInstructionTarget = true;
        state.instructionTarget = textarea;

        return { controller, textarea };
    };

    it("prepends system note to empty textarea", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "";

        controller.handleUploadComplete();

        expect(textarea.value).toBe("[System Note: a new remote asset has been uploaded]\n\n");
    });

    it("prepends system note to existing text", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "Please add an image here";

        controller.handleUploadComplete();

        expect(textarea.value).toBe("[System Note: a new remote asset has been uploaded]\n\nPlease add an image here");
    });

    it("does not duplicate system note if already present", () => {
        const { controller, textarea } = createControllerWithInstructionTarget();
        textarea.value = "[System Note: a new remote asset has been uploaded]\n\nSome text";

        controller.handleUploadComplete();

        expect(textarea.value).toBe("[System Note: a new remote asset has been uploaded]\n\nSome text");
    });

    it("does nothing without instruction target", () => {
        const controller = Object.create(
            ChatBasedContentEditorController.prototype,
        ) as ChatBasedContentEditorController;
        const state = controller as unknown as { hasInstructionTarget: boolean };
        state.hasInstructionTarget = false;

        // Should not throw
        expect(() => controller.handleUploadComplete()).not.toThrow();
    });
});

describe("ChatBasedContentEditorController activity indicators", () => {
    let controller: ChatBasedContentEditorController | null = null;

    const testTranslations = {
        working: "Working",
        thinking: "Thinking",
        filesModified: "files modified",
    };

    const createControllerInstance = (): ChatBasedContentEditorController => {
        const instance = Object.create(ChatBasedContentEditorController.prototype) as ChatBasedContentEditorController;
        const state = instance as unknown as {
            activityThinkingTimerId: ReturnType<typeof setInterval> | null;
            activityWorkingTimeoutId: ReturnType<typeof setTimeout> | null;
            activityToolCallCount: number;
            activityThinkingSeconds: number;
            activityWorkingActive: boolean;
            translationsValue: typeof testTranslations;
        };

        state.activityThinkingTimerId = null;
        state.activityWorkingTimeoutId = null;
        state.activityToolCallCount = 0;
        state.activityThinkingSeconds = 0;
        state.activityWorkingActive = false;
        state.translationsValue = testTranslations;

        controller = instance;

        return instance;
    };

    beforeEach(() => {
        document.body.innerHTML = "";
    });

    afterEach(() => {
        if (controller) {
            const safeController = controller as unknown as {
                stopThinkingTimer?: () => void;
                stopWorkingTimeout?: () => void;
            };
            safeController.stopThinkingTimer?.();
            safeController.stopWorkingTimeout?.();
        }
        controller = null;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it("renders Working/Thinking badges with initial values", async () => {
        const instance = createControllerInstance();
        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();

        const workingBadge = technicalContainer.querySelector<HTMLElement>('[data-activity-working-badge="1"]');
        const workingCount = technicalContainer.querySelector<HTMLElement>('[data-activity-working-count="1"]');
        const thinkingBadge = technicalContainer.querySelector<HTMLElement>('[data-activity-thinking-badge="1"]');
        const thinkingSeconds = technicalContainer.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');

        expect(workingBadge).not.toBeNull();
        expect(workingCount?.textContent).toBe("0");
        expect(workingBadge?.classList.contains("activity-badge-inactive")).toBe(true);

        expect(thinkingBadge).not.toBeNull();
        expect(thinkingSeconds?.textContent).toBe("0");
        expect(thinkingBadge?.classList.contains("activity-badge-thinking-active")).toBe(true);
        expect(thinkingBadge?.classList.contains("activity-badge-active")).toBe(true);
    });

    it("increments the thinking timer every second", async () => {
        vi.useFakeTimers();
        const instance = createControllerInstance();

        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();

        const thinkingSeconds = technicalContainer.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');
        expect(thinkingSeconds?.textContent).toBe("0");

        vi.advanceTimersByTime(3000);
        expect(thinkingSeconds?.textContent).toBe("3");
    });

    it("activates Working badge on tool_calling and clears after timeout", async () => {
        vi.useFakeTimers();
        const instance = createControllerInstance();

        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();
        const wrapper = document.createElement("div");
        wrapper.appendChild(technicalContainer);

        const event = { kind: "tool_calling", toolName: "ReadFile" };
        (
            instance as unknown as { appendTechnicalEvent: (container: HTMLElement, ev: unknown) => void }
        ).appendTechnicalEvent(wrapper, event);

        const workingBadge = technicalContainer.querySelector<HTMLElement>('[data-activity-working-badge="1"]');
        const workingCount = technicalContainer.querySelector<HTMLElement>('[data-activity-working-count="1"]');
        const workingCountClasses = workingCount?.classList;

        expect(workingCount?.textContent).toBe("1");
        expect(workingBadge?.classList.contains("activity-badge-working-active")).toBe(true);
        expect(workingBadge?.classList.contains("activity-badge-active")).toBe(true);
        expect(workingCountClasses?.contains("activity-seconds-working")).toBe(true);

        vi.advanceTimersByTime(2000);

        expect(workingBadge?.classList.contains("activity-badge-inactive")).toBe(true);
        expect(workingCountClasses?.contains("activity-seconds-inactive")).toBe(true);
    });

    it("stops activity badges when the session completes", async () => {
        vi.useFakeTimers();
        const instance = createControllerInstance();

        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();
        const wrapper = document.createElement("div");
        wrapper.appendChild(technicalContainer);

        vi.advanceTimersByTime(1000);
        const thinkingSeconds = technicalContainer.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');
        expect(thinkingSeconds?.textContent).toBe("1");

        (
            instance as unknown as { markTechnicalContainerComplete: (container: HTMLElement) => void }
        ).markTechnicalContainerComplete(wrapper);

        const thinkingBadge = technicalContainer.querySelector<HTMLElement>('[data-activity-thinking-badge="1"]');
        expect(thinkingBadge?.classList.contains("activity-badge-inactive")).toBe(true);

        vi.advanceTimersByTime(2000);
        expect(thinkingSeconds?.textContent).toBe("1");
    });

    it("renders completed activity badges with tool call counts", async () => {
        const instance = createControllerInstance();

        const turn = {
            events: [
                { payload: JSON.stringify({ kind: "tool_calling", toolName: "ReadFile" }) },
                { payload: JSON.stringify({ kind: "tool_called", toolResult: "ok" }) },
                { payload: JSON.stringify({ kind: "tool_calling", toolName: "WriteFile" }) },
            ],
            response: "",
        };

        const completedContainer = (
            instance as unknown as {
                createCompletedTechnicalContainer: (turnData: unknown) => HTMLElement;
            }
        ).createCompletedTechnicalContainer(turn);

        const workingBadge = completedContainer.querySelector<HTMLElement>('[data-activity-working-badge="1"]');
        const workingCount = completedContainer.querySelector<HTMLElement>('[data-activity-working-count="1"]');
        const thinkingBadge = completedContainer.querySelector<HTMLElement>('[data-activity-thinking-badge="1"]');
        const thinkingSeconds = completedContainer.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');

        expect(workingCount?.textContent).toBe("2");
        expect(thinkingSeconds?.textContent).toBe("—");
        expect(workingBadge?.classList.contains("activity-badge-inactive")).toBe(true);
        expect(thinkingBadge?.classList.contains("activity-badge-inactive")).toBe(true);
    });
});

describe("ChatBasedContentEditorController handleChunk progress chunks", () => {
    it("appends progress line when chunk type is progress and payload has message", () => {
        const instance = Object.create(ChatBasedContentEditorController.prototype) as ChatBasedContentEditorController;
        (instance as unknown as { translationsValue: Record<string, string> }).translationsValue = {
            cancelled: "Cancelled",
        };

        const container = document.createElement("div");
        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();
        container.appendChild(technicalContainer);

        const chunk = {
            id: 1,
            chunkType: "progress",
            payload: JSON.stringify({ message: "Reading about.html" }),
        };
        (
            instance as unknown as {
                handleChunk: (
                    chunk: { id: number; chunkType: string; payload: string },
                    container: HTMLElement,
                ) => boolean;
            }
        ).handleChunk(chunk, container);

        const progressWrapper = container.querySelector<HTMLElement>('[data-progress-wrapper="1"]');
        expect(progressWrapper).not.toBeNull();
        const progressContainer = progressWrapper!.querySelector<HTMLElement>('[data-progress-container="1"]');
        expect(progressContainer).not.toBeNull();
        const lines = progressContainer!.querySelectorAll("div");
        expect(lines.length).toBe(1);
        expect(lines[0].textContent).toBe("Reading about.html");
    });

    it("appends multiple progress lines in order", () => {
        const instance = Object.create(ChatBasedContentEditorController.prototype) as ChatBasedContentEditorController;
        (instance as unknown as { translationsValue: Record<string, string> }).translationsValue = {
            cancelled: "Cancelled",
        };

        const container = document.createElement("div");
        const technicalContainer = (
            instance as unknown as { createTechnicalMessagesContainer: () => HTMLElement }
        ).createTechnicalMessagesContainer();
        container.appendChild(technicalContainer);

        const handleChunk = (
            instance as unknown as {
                handleChunk: (
                    chunk: { id: number; chunkType: string; payload: string },
                    container: HTMLElement,
                ) => boolean;
            }
        ).handleChunk.bind(instance);

        handleChunk({ id: 1, chunkType: "progress", payload: JSON.stringify({ message: "Thinking…" }) }, container);
        handleChunk(
            { id: 2, chunkType: "progress", payload: JSON.stringify({ message: "Editing dist/landing-1.html" }) },
            container,
        );

        const progressContainer = container.querySelector<HTMLElement>('[data-progress-container="1"]');
        expect(progressContainer).not.toBeNull();
        const lines = progressContainer!.querySelectorAll("div");
        expect(lines.length).toBe(2);
        expect(lines[0].textContent).toBe("Thinking…");
        expect(lines[1].textContent).toBe("Editing dist/landing-1.html");
    });
});

describe("ChatBasedContentEditorController prefillMessage", () => {
    const createControllerWithPrefill = (
        prefillMessage: string,
    ): {
        controller: ChatBasedContentEditorController;
        textarea: HTMLTextAreaElement;
    } => {
        const textarea = document.createElement("textarea");
        textarea.id = "instruction";
        document.body.appendChild(textarea);

        const controller = Object.create(
            ChatBasedContentEditorController.prototype,
        ) as ChatBasedContentEditorController;
        const state = controller as unknown as {
            hasInstructionTarget: boolean;
            instructionTarget: HTMLTextAreaElement;
            prefillMessageValue: string;
            readOnlyValue: boolean;
            contextUsageValue: undefined;
            activeSessionValue: null;
            turnsValue: [];
            contextUsageUrlValue: string;
        };
        state.hasInstructionTarget = true;
        state.instructionTarget = textarea;
        state.prefillMessageValue = prefillMessage;
        state.readOnlyValue = false;
        state.contextUsageValue = undefined;
        state.activeSessionValue = null;
        state.turnsValue = [];
        state.contextUsageUrlValue = "";

        // Stub methods that connect() calls
        (
            controller as unknown as {
                renderCompletedTurnsTechnicalContainers: () => void;
                startContextUsagePolling: () => void;
            }
        ).renderCompletedTurnsTechnicalContainers = () => {};
        (
            controller as unknown as {
                startContextUsagePolling: () => void;
            }
        ).startContextUsagePolling = () => {};

        return { controller, textarea };
    };

    beforeEach(() => {
        document.body.innerHTML = "";
    });

    it("should pre-fill instruction textarea with prefillMessage on connect", () => {
        const { controller, textarea } = createControllerWithPrefill(
            "Embed images sunset.jpg, office.jpg into page index.html",
        );

        controller.connect();

        expect(textarea.value).toBe("Embed images sunset.jpg, office.jpg into page index.html");
    });

    it("should not pre-fill when prefillMessage is empty", () => {
        const { controller, textarea } = createControllerWithPrefill("");
        textarea.value = "Existing text";

        controller.connect();

        expect(textarea.value).toBe("Existing text");
    });

    it("should focus the textarea when prefillMessage is set", () => {
        const { controller, textarea } = createControllerWithPrefill("Embed images into page");

        const focusSpy = vi.spyOn(textarea, "focus");

        controller.connect();

        expect(focusSpy).toHaveBeenCalled();
    });
});
