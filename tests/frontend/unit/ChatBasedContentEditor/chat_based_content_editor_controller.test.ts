import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import ChatBasedContentEditorController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";

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
