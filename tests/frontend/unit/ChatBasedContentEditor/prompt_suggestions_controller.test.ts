import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import PromptSuggestionsController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/prompt_suggestions_controller.ts";

describe("PromptSuggestionsController", () => {
    interface ControllerFixture {
        controller: PromptSuggestionsController;
        suggestionButtons: HTMLButtonElement[];
        expandButton: HTMLButtonElement;
        collapseButton: HTMLButtonElement;
        expandCollapseWrapper: HTMLElement;
        suggestionList: HTMLElement;
        formModal: HTMLElement;
        formInput: HTMLTextAreaElement;
        formTitle: HTMLElement;
        deleteModal: HTMLElement;
    }

    const createController = (): ControllerFixture => {
        const controller = Object.create(PromptSuggestionsController.prototype) as PromptSuggestionsController;

        // Create suggestion buttons wrapped in row divs
        const suggestionList = document.createElement("div");
        const suggestionButtons: HTMLButtonElement[] = [];
        for (let i = 0; i < 5; i++) {
            const row = document.createElement("div");
            row.dataset.index = String(i);
            row.className = "group flex items-start gap-1";

            const btn = document.createElement("button");
            btn.dataset.text = `Suggestion ${i + 1}`;
            if (i >= 3) {
                btn.classList.add("hidden");
            }
            suggestionButtons.push(btn);

            const actions = document.createElement("div");
            actions.className = "flex-shrink-0";
            if (i >= 3) {
                actions.classList.add("hidden");
            }

            row.appendChild(btn);
            row.appendChild(actions);
            suggestionList.appendChild(row);
        }

        // Create expand/collapse buttons wrapped in a container
        const expandCollapseWrapper = document.createElement("div");
        const expandButton = document.createElement("button");
        const collapseButton = document.createElement("button");
        collapseButton.classList.add("hidden");
        expandCollapseWrapper.appendChild(expandButton);
        expandCollapseWrapper.appendChild(collapseButton);

        // Create modal elements
        const formModal = document.createElement("div");
        formModal.classList.add("hidden");
        const formInput = document.createElement("textarea");
        const formTitle = document.createElement("h3");
        const deleteModal = document.createElement("div");
        deleteModal.classList.add("hidden");

        // Set up controller state
        const state = controller as unknown as {
            suggestionTargets: HTMLButtonElement[];
            hasExpandButtonTarget: boolean;
            expandButtonTarget: HTMLButtonElement;
            hasCollapseButtonTarget: boolean;
            collapseButtonTarget: HTMLButtonElement;
            hasExpandCollapseWrapperTarget: boolean;
            expandCollapseWrapperTarget: HTMLElement;
            maxVisibleValue: number;
            dispatch: (name: string, options: unknown) => void;
            hasSuggestionListTarget: boolean;
            suggestionListTarget: HTMLElement;
            hasFormModalTarget: boolean;
            formModalTarget: HTMLElement;
            hasFormInputTarget: boolean;
            formInputTarget: HTMLTextAreaElement;
            hasFormTitleTarget: boolean;
            formTitleTarget: HTMLElement;
            hasDeleteModalTarget: boolean;
            deleteModalTarget: HTMLElement;
            createUrlValue: string;
            updateUrlTemplateValue: string;
            deleteUrlTemplateValue: string;
            csrfTokenValue: string;
            addTitleValue: string;
            editTitleValue: string;
            placeholderValue: string;
            showMoreTemplateValue: string;
            showLessLabelValue: string;
        };

        state.suggestionTargets = suggestionButtons;
        state.hasExpandButtonTarget = true;
        state.expandButtonTarget = expandButton;
        state.hasCollapseButtonTarget = true;
        state.collapseButtonTarget = collapseButton;
        state.hasExpandCollapseWrapperTarget = true;
        state.expandCollapseWrapperTarget = expandCollapseWrapper;
        state.maxVisibleValue = 3;
        state.dispatch = vi.fn();
        state.hasSuggestionListTarget = true;
        state.suggestionListTarget = suggestionList;
        state.hasFormModalTarget = true;
        state.formModalTarget = formModal;
        state.hasFormInputTarget = true;
        state.formInputTarget = formInput;
        state.hasFormTitleTarget = true;
        state.formTitleTarget = formTitle;
        state.hasDeleteModalTarget = true;
        state.deleteModalTarget = deleteModal;
        state.createUrlValue = "/conversation/123/prompt-suggestions";
        state.updateUrlTemplateValue = "/conversation/123/prompt-suggestions/99999";
        state.deleteUrlTemplateValue = "/conversation/123/prompt-suggestions/99999";
        state.csrfTokenValue = "test-csrf-token";
        state.addTitleValue = "Add new suggestion";
        state.editTitleValue = "Edit suggestion";
        state.placeholderValue = "Enter suggestion...";
        state.showMoreTemplateValue = "+{count} more";
        state.showLessLabelValue = "Show less";

        return {
            controller,
            suggestionButtons,
            expandButton,
            collapseButton,
            expandCollapseWrapper,
            suggestionList,
            formModal,
            formInput,
            formTitle,
            deleteModal,
        };
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
        it("adds suggestion-expanded class immediately on hover start", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverStart(event);

            expect(button.classList.contains("suggestion-expanded")).toBe(true);
        });

        it("removes class immediately on hover end", () => {
            const { controller } = createController();
            const button = document.createElement("button");
            button.classList.add("suggestion-expanded");
            const event = { currentTarget: button } as unknown as Event;

            controller.hoverEnd(event);

            expect(button.classList.contains("suggestion-expanded")).toBe(false);
        });
    });

    describe("showAddModal", () => {
        it("opens form modal with empty input and add title", () => {
            const { controller, formModal, formInput, formTitle } = createController();

            controller.showAddModal();

            expect(formModal.classList.contains("hidden")).toBe(false);
            expect(formInput.value).toBe("");
            expect(formTitle.textContent).toBe("Add new suggestion");
        });

        it("sets placeholder on textarea", () => {
            const { controller, formInput } = createController();

            controller.showAddModal();

            expect(formInput.placeholder).toBe("Enter suggestion...");
        });
    });

    describe("showEditModal", () => {
        it("opens form modal prefilled with suggestion text and edit title", () => {
            const { controller, formModal, formInput, formTitle } = createController();

            const button = document.createElement("button");
            button.dataset.index = "1";
            button.dataset.text = "Existing suggestion";
            const event = { currentTarget: button } as unknown as Event;

            controller.showEditModal(event);

            expect(formModal.classList.contains("hidden")).toBe(false);
            expect(formInput.value).toBe("Existing suggestion");
            expect(formTitle.textContent).toBe("Edit suggestion");
        });
    });

    describe("hideFormModal", () => {
        it("hides the form modal", () => {
            const { controller, formModal } = createController();

            // Open the modal first
            controller.showAddModal();
            expect(formModal.classList.contains("hidden")).toBe(false);

            controller.hideFormModal();

            expect(formModal.classList.contains("hidden")).toBe(true);
        });
    });

    describe("confirmDelete", () => {
        it("opens delete confirmation modal", () => {
            const { controller, deleteModal } = createController();

            const button = document.createElement("button");
            button.dataset.index = "2";
            const event = { currentTarget: button } as unknown as Event;

            controller.confirmDelete(event);

            expect(deleteModal.classList.contains("hidden")).toBe(false);
        });
    });

    describe("cancelDelete", () => {
        it("hides delete confirmation modal", () => {
            const { controller, deleteModal } = createController();

            // Open first
            const button = document.createElement("button");
            button.dataset.index = "0";
            controller.confirmDelete({
                currentTarget: button,
            } as unknown as Event);
            expect(deleteModal.classList.contains("hidden")).toBe(false);

            controller.cancelDelete();

            expect(deleteModal.classList.contains("hidden")).toBe(true);
        });
    });

    describe("submitForm", () => {
        let fetchSpy: ReturnType<typeof vi.fn>;

        beforeEach(() => {
            fetchSpy = vi.fn();
            vi.stubGlobal("fetch", fetchSpy);
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        it("sends POST request in add mode", async () => {
            const { controller, formInput } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        suggestions: ["Existing", "New suggestion"],
                    }),
            });

            controller.showAddModal();
            formInput.value = "New suggestion";

            await controller.submitForm();

            expect(fetchSpy).toHaveBeenCalledWith(
                "/conversation/123/prompt-suggestions",
                expect.objectContaining({
                    method: "POST",
                    body: JSON.stringify({ text: "New suggestion" }),
                }),
            );
        });

        it("sends PUT request in edit mode", async () => {
            const { controller, formInput } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        suggestions: ["Updated suggestion", "Second"],
                    }),
            });

            const button = document.createElement("button");
            button.dataset.index = "0";
            button.dataset.text = "Original";
            controller.showEditModal({
                currentTarget: button,
            } as unknown as Event);
            formInput.value = "Updated suggestion";

            await controller.submitForm();

            expect(fetchSpy).toHaveBeenCalledWith(
                "/conversation/123/prompt-suggestions/0",
                expect.objectContaining({
                    method: "PUT",
                    body: JSON.stringify({ text: "Updated suggestion" }),
                }),
            );
        });

        it("does not send request when input is empty", async () => {
            const { controller, formInput } = createController();

            controller.showAddModal();
            formInput.value = "   ";

            await controller.submitForm();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("includes CSRF token in headers", async () => {
            const { controller, formInput } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ suggestions: ["New suggestion"] }),
            });

            controller.showAddModal();
            formInput.value = "New suggestion";

            await controller.submitForm();

            expect(fetchSpy).toHaveBeenCalledWith(
                expect.any(String),
                expect.objectContaining({
                    headers: expect.objectContaining({
                        "X-CSRF-Token": "test-csrf-token",
                    }),
                }),
            );
        });

        it("hides modal after successful submit", async () => {
            const { controller, formModal, formInput } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ suggestions: ["New suggestion"] }),
            });

            controller.showAddModal();
            formInput.value = "New suggestion";

            await controller.submitForm();

            expect(formModal.classList.contains("hidden")).toBe(true);
        });
    });

    describe("executeDelete", () => {
        let fetchSpy: ReturnType<typeof vi.fn>;

        beforeEach(() => {
            fetchSpy = vi.fn();
            vi.stubGlobal("fetch", fetchSpy);
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        it("sends DELETE request with correct index", async () => {
            const { controller } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ suggestions: ["Remaining suggestion"] }),
            });

            const button = document.createElement("button");
            button.dataset.index = "1";
            controller.confirmDelete({
                currentTarget: button,
            } as unknown as Event);

            await controller.executeDelete();

            expect(fetchSpy).toHaveBeenCalledWith(
                "/conversation/123/prompt-suggestions/1",
                expect.objectContaining({ method: "DELETE" }),
            );
        });

        it("does not send request when deleteIndex is null", async () => {
            const { controller } = createController();

            await controller.executeDelete();

            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it("hides delete modal after execution", async () => {
            const { controller, deleteModal } = createController();

            fetchSpy.mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({ suggestions: [] }),
            });

            const button = document.createElement("button");
            button.dataset.index = "0";
            controller.confirmDelete({
                currentTarget: button,
            } as unknown as Event);

            await controller.executeDelete();

            expect(deleteModal.classList.contains("hidden")).toBe(true);
        });
    });

    describe("refreshSuggestionsList", () => {
        it("rebuilds the suggestion list from new data", () => {
            const { controller, suggestionList } = createController();

            controller.refreshSuggestionsList(["Alpha", "Beta", "Gamma"]);

            // Use direct children to avoid matching nested elements with data-index
            const rows = Array.from(suggestionList.children);
            expect(rows.length).toBe(3);

            const buttons = suggestionList.querySelectorAll('[data-prompt-suggestions-target="suggestion"]');
            expect(buttons.length).toBe(3);
            expect(buttons[0].textContent).toBe("Alpha");
            expect(buttons[1].textContent).toBe("Beta");
            expect(buttons[2].textContent).toBe("Gamma");
        });

        it("hides suggestions beyond maxVisible", () => {
            const { controller, suggestionList } = createController();

            controller.refreshSuggestionsList(["A", "B", "C", "D", "E"]);

            const buttons = suggestionList.querySelectorAll('[data-prompt-suggestions-target="suggestion"]');
            expect(buttons[0].classList.contains("hidden")).toBe(false);
            expect(buttons[2].classList.contains("hidden")).toBe(false);
            expect(buttons[3].classList.contains("hidden")).toBe(true);
            expect(buttons[4].classList.contains("hidden")).toBe(true);
        });

        it("clears existing content before rebuilding", () => {
            const { controller, suggestionList } = createController();

            // Initial state has 5 suggestions
            expect(suggestionList.children.length).toBe(5);

            controller.refreshSuggestionsList(["Only one"]);

            expect(suggestionList.children.length).toBe(1);
        });

        it("handles empty suggestions list", () => {
            const { controller, suggestionList } = createController();

            controller.refreshSuggestionsList([]);

            expect(suggestionList.children.length).toBe(0);
        });

        it("sets correct data-index on each row", () => {
            const { controller, suggestionList } = createController();

            controller.refreshSuggestionsList(["First", "Second"]);

            // Use direct children to avoid matching nested elements with data-index
            const rows = Array.from(suggestionList.children) as HTMLElement[];
            expect(rows[0].dataset.index).toBe("0");
            expect(rows[1].dataset.index).toBe("1");
        });

        it("includes edit and delete action buttons per suggestion", () => {
            const { controller, suggestionList } = createController();

            controller.refreshSuggestionsList(["Test"]);

            const editBtn = suggestionList.querySelector('[data-action*="showEditModal"]');
            const deleteBtn = suggestionList.querySelector('[data-action*="confirmDelete"]');

            expect(editBtn).not.toBeNull();
            expect(deleteBtn).not.toBeNull();
        });

        it("shows expand/collapse wrapper when suggestions exceed maxVisible", () => {
            const { controller, expandCollapseWrapper } = createController();

            // Start hidden
            expandCollapseWrapper.classList.add("hidden");

            controller.refreshSuggestionsList(["A", "B", "C", "D"]);

            expect(expandCollapseWrapper.classList.contains("hidden")).toBe(false);
        });

        it("hides expand/collapse wrapper when suggestions are within maxVisible", () => {
            const { controller, expandCollapseWrapper } = createController();

            // Start visible (simulating previous > 3 state)
            expandCollapseWrapper.classList.remove("hidden");

            controller.refreshSuggestionsList(["A", "B"]);

            expect(expandCollapseWrapper.classList.contains("hidden")).toBe(true);
        });

        it("hides expand/collapse wrapper when suggestions equal maxVisible", () => {
            const { controller, expandCollapseWrapper } = createController();

            expandCollapseWrapper.classList.remove("hidden");

            controller.refreshSuggestionsList(["A", "B", "C"]);

            expect(expandCollapseWrapper.classList.contains("hidden")).toBe(true);
        });

        it("updates expand button text with correct hidden count", () => {
            const { controller, expandButton } = createController();

            controller.refreshSuggestionsList(["A", "B", "C", "D", "E"]);

            expect(expandButton.textContent).toBe("+2 more");
        });

        it("resets to collapsed state after refresh", () => {
            const { controller, expandButton, collapseButton } = createController();

            // Simulate expanded state
            expandButton.classList.add("hidden");
            collapseButton.classList.remove("hidden");

            controller.refreshSuggestionsList(["A", "B", "C", "D"]);

            expect(expandButton.classList.contains("hidden")).toBe(false);
            expect(collapseButton.classList.contains("hidden")).toBe(true);
        });

        it("sets collapse button text from showLessLabel value", () => {
            const { controller, collapseButton } = createController();

            controller.refreshSuggestionsList(["A", "B", "C", "D"]);

            expect(collapseButton.textContent).toBe("Show less");
        });

        it("hides wrapper when list is emptied", () => {
            const { controller, expandCollapseWrapper } = createController();

            expandCollapseWrapper.classList.remove("hidden");

            controller.refreshSuggestionsList([]);

            expect(expandCollapseWrapper.classList.contains("hidden")).toBe(true);
        });
    });
});
