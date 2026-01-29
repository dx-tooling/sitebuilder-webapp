import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import PromptSuggestionsController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/prompt_suggestions_controller.ts";

describe("PromptSuggestionsController", () => {
    const createController = (): {
        controller: PromptSuggestionsController;
        suggestionButtons: HTMLButtonElement[];
        expandButton: HTMLButtonElement;
        collapseButton: HTMLButtonElement;
    } => {
        const controller = Object.create(PromptSuggestionsController.prototype) as PromptSuggestionsController;

        // Create suggestion buttons
        const suggestionButtons: HTMLButtonElement[] = [];
        for (let i = 0; i < 5; i++) {
            const btn = document.createElement("button");
            btn.dataset.text = `Suggestion ${i + 1}`;
            if (i >= 3) {
                btn.classList.add("hidden");
            }
            suggestionButtons.push(btn);
        }

        // Create expand/collapse buttons
        const expandButton = document.createElement("button");
        const collapseButton = document.createElement("button");
        collapseButton.classList.add("hidden");

        // Set up controller state
        const state = controller as unknown as {
            suggestionTargets: HTMLButtonElement[];
            hasExpandButtonTarget: boolean;
            expandButtonTarget: HTMLButtonElement;
            hasCollapseButtonTarget: boolean;
            collapseButtonTarget: HTMLButtonElement;
            maxVisibleValue: number;
            hoverDelayValue: number;
            hoverTimeoutId: ReturnType<typeof setTimeout> | null;
            dispatch: (name: string, options: unknown) => void;
        };

        state.suggestionTargets = suggestionButtons;
        state.hasExpandButtonTarget = true;
        state.expandButtonTarget = expandButton;
        state.hasCollapseButtonTarget = true;
        state.collapseButtonTarget = collapseButton;
        state.maxVisibleValue = 3;
        state.hoverDelayValue = 500;
        state.hoverTimeoutId = null;
        state.dispatch = vi.fn();

        return { controller, suggestionButtons, expandButton, collapseButton };
    };

    describe("insert", () => {
        it("dispatches insert event with suggestion text", () => {
            const { controller } = createController();
            const state = controller as unknown as {
                dispatch: ReturnType<typeof vi.fn>;
            };

            const button = document.createElement("button");
            button.dataset.text = "Test suggestion";
            const event = { currentTarget: button } as unknown as Event;

            controller.insert(event);

            expect(state.dispatch).toHaveBeenCalledWith("insert", {
                detail: { text: "Test suggestion" },
                bubbles: true,
            });
        });

        it("does not dispatch when text is empty", () => {
            const { controller } = createController();
            const state = controller as unknown as {
                dispatch: ReturnType<typeof vi.fn>;
            };

            const button = document.createElement("button");
            button.dataset.text = "";
            const event = { currentTarget: button } as unknown as Event;

            controller.insert(event);

            expect(state.dispatch).not.toHaveBeenCalled();
        });

        it("does not dispatch when data-text is missing", () => {
            const { controller } = createController();
            const state = controller as unknown as {
                dispatch: ReturnType<typeof vi.fn>;
            };

            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.insert(event);

            expect(state.dispatch).not.toHaveBeenCalled();
        });
    });

    describe("expand", () => {
        it("shows all hidden suggestions", () => {
            const { controller, suggestionButtons } = createController();

            // Initially buttons 3,4 are hidden
            expect(suggestionButtons[3].classList.contains("hidden")).toBe(true);
            expect(suggestionButtons[4].classList.contains("hidden")).toBe(true);

            controller.expand();

            // After expand, all should be visible
            suggestionButtons.forEach((btn) => {
                expect(btn.classList.contains("hidden")).toBe(false);
            });
        });

        it("hides expand button and shows collapse button", () => {
            const { controller, expandButton, collapseButton } = createController();

            expect(collapseButton.classList.contains("hidden")).toBe(true);

            controller.expand();

            expect(expandButton.classList.contains("hidden")).toBe(true);
            expect(collapseButton.classList.contains("hidden")).toBe(false);
        });
    });

    describe("collapse", () => {
        beforeEach(() => {
            // Simulate expanded state
        });

        it("hides suggestions beyond maxVisible", () => {
            const { controller, suggestionButtons } = createController();

            // First expand to show all
            suggestionButtons.forEach((btn) => btn.classList.remove("hidden"));

            controller.collapse();

            // First 3 should be visible, rest hidden
            expect(suggestionButtons[0].classList.contains("hidden")).toBe(false);
            expect(suggestionButtons[1].classList.contains("hidden")).toBe(false);
            expect(suggestionButtons[2].classList.contains("hidden")).toBe(false);
            expect(suggestionButtons[3].classList.contains("hidden")).toBe(true);
            expect(suggestionButtons[4].classList.contains("hidden")).toBe(true);
        });

        it("shows expand button and hides collapse button", () => {
            const { controller, expandButton, collapseButton } = createController();

            // Simulate expanded state
            expandButton.classList.add("hidden");
            collapseButton.classList.remove("hidden");

            controller.collapse();

            expect(expandButton.classList.contains("hidden")).toBe(false);
            expect(collapseButton.classList.contains("hidden")).toBe(true);
        });
    });

    describe("hover functionality", () => {
        beforeEach(() => {
            vi.useFakeTimers();
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it("adds suggestion-expanded class after hover delay", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverStart(event);

            // Class should not be added immediately
            expect(button.classList.contains("suggestion-expanded")).toBe(false);

            // Advance timer past the delay
            vi.advanceTimersByTime(500);

            // Now class should be added
            expect(button.classList.contains("suggestion-expanded")).toBe(true);
        });

        it("does not add class before delay completes", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverStart(event);

            // Advance timer but not past the delay
            vi.advanceTimersByTime(400);

            expect(button.classList.contains("suggestion-expanded")).toBe(false);
        });

        it("removes class immediately on hover end", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            button.classList.add("suggestion-expanded");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverEnd(event);

            expect(button.classList.contains("suggestion-expanded")).toBe(false);
        });

        it("cancels pending expansion on hover end", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverStart(event);
            vi.advanceTimersByTime(300); // Partial delay

            controller.hoverEnd(event);
            vi.advanceTimersByTime(300); // Past original delay

            // Class should not be added because hover ended
            expect(button.classList.contains("suggestion-expanded")).toBe(false);
        });

        it("clears timeout on disconnect", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverStart(event);
            controller.disconnect();

            vi.advanceTimersByTime(600);

            // Class should not be added because disconnect cleared the timeout
            expect(button.classList.contains("suggestion-expanded")).toBe(false);
        });
    });
});
