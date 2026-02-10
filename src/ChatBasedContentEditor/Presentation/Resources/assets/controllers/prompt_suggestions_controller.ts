import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for prompt suggestions.
 * Handles display (expand/collapse, insert) and CRUD (add, edit, delete) via modals.
 */
export default class extends Controller {
    static targets = [
        "suggestion",
        "expandButton",
        "collapseButton",
        "expandCollapseWrapper",
        "suggestionList",
        "formModal",
        "formInput",
        "formTitle",
        "deleteModal",
    ];

    static values = {
        maxVisible: { type: Number, default: 3 },
        createUrl: { type: String, default: "" },
        updateUrlTemplate: { type: String, default: "" },
        deleteUrlTemplate: { type: String, default: "" },
        csrfToken: { type: String, default: "" },
        addTitle: { type: String, default: "Add new suggestion" },
        editTitle: { type: String, default: "Edit suggestion" },
        placeholder: { type: String, default: "Enter suggestion..." },
        saveLabel: { type: String, default: "Save" },
        cancelLabel: { type: String, default: "Cancel" },
        deleteConfirmText: {
            type: String,
            default: "Really delete this suggestion?",
        },
        deleteLabel: { type: String, default: "Delete" },
        showMoreTemplate: { type: String, default: "+{count} more" },
        showLessLabel: { type: String, default: "Show less" },
    };

    declare readonly suggestionTargets: HTMLButtonElement[];
    declare readonly hasExpandButtonTarget: boolean;
    declare readonly expandButtonTarget: HTMLButtonElement;
    declare readonly hasCollapseButtonTarget: boolean;
    declare readonly collapseButtonTarget: HTMLButtonElement;
    declare readonly hasExpandCollapseWrapperTarget: boolean;
    declare readonly expandCollapseWrapperTarget: HTMLElement;
    declare readonly maxVisibleValue: number;

    declare readonly hasSuggestionListTarget: boolean;
    declare readonly suggestionListTarget: HTMLElement;
    declare readonly hasFormModalTarget: boolean;
    declare readonly formModalTarget: HTMLElement;
    declare readonly hasFormInputTarget: boolean;
    declare readonly formInputTarget: HTMLTextAreaElement;
    declare readonly hasFormTitleTarget: boolean;
    declare readonly formTitleTarget: HTMLElement;
    declare readonly hasDeleteModalTarget: boolean;
    declare readonly deleteModalTarget: HTMLElement;

    declare readonly createUrlValue: string;
    declare readonly updateUrlTemplateValue: string;
    declare readonly deleteUrlTemplateValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly addTitleValue: string;
    declare readonly editTitleValue: string;
    declare readonly placeholderValue: string;
    declare readonly showMoreTemplateValue: string;
    declare readonly showLessLabelValue: string;

    /** null = add mode, number = edit mode (index of suggestion being edited) */
    editIndex: number | null = null;

    /** Index of the suggestion pending deletion */
    deleteIndex: number | null = null;

    // ─── Display: insert, hover, expand/collapse ────────────────

    /**
     * Handle click on a suggestion button.
     * Dispatches a custom event with the suggestion text for the chat controller to handle.
     */
    insert(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        const text = button.dataset.text || "";

        if (text) {
            this.dispatch("insert", {
                detail: { text },
                bubbles: true,
            });
        }
    }

    /**
     * Handle mouse enter on a suggestion - expand immediately.
     */
    hoverStart(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        button.classList.add("suggestion-expanded");
    }

    /**
     * Handle mouse leave on a suggestion - collapse immediately.
     */
    hoverEnd(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        button.classList.remove("suggestion-expanded");
    }

    /**
     * Show all hidden suggestions and toggle expand/collapse buttons.
     */
    expand(): void {
        // Show all hidden suggestion rows (the parent wrapper divs)
        this.suggestionTargets.forEach((button) => {
            button.classList.remove("hidden");
            const row = button.closest("[data-index]");
            if (row) {
                const actions = row.querySelector(".flex-shrink-0") as HTMLElement | null;
                if (actions) {
                    actions.classList.remove("hidden");
                }
            }
        });

        if (this.hasExpandButtonTarget) {
            this.expandButtonTarget.classList.add("hidden");
        }
        if (this.hasCollapseButtonTarget) {
            this.collapseButtonTarget.classList.remove("hidden");
        }
    }

    /**
     * Hide suggestions beyond maxVisible and toggle expand/collapse buttons.
     */
    collapse(): void {
        this.suggestionTargets.forEach((button, index) => {
            if (index >= this.maxVisibleValue) {
                button.classList.add("hidden");
                const row = button.closest("[data-index]");
                if (row) {
                    const actions = row.querySelector(".flex-shrink-0") as HTMLElement | null;
                    if (actions) {
                        actions.classList.add("hidden");
                    }
                }
            }
        });

        if (this.hasExpandButtonTarget) {
            this.expandButtonTarget.classList.remove("hidden");
        }
        if (this.hasCollapseButtonTarget) {
            this.collapseButtonTarget.classList.add("hidden");
        }
    }

    // ─── Add / Edit modal ───────────────────────────────────────

    /**
     * Open the form modal in "add" mode (empty input).
     */
    showAddModal(): void {
        this.editIndex = null;
        this.openFormModal("", this.addTitleValue);
    }

    /**
     * Open the form modal in "edit" mode (prefilled with the suggestion text).
     */
    showEditModal(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        const index = parseInt(button.dataset.index || "0", 10);
        const text = button.dataset.text || "";

        this.editIndex = index;
        this.openFormModal(text, this.editTitleValue);
    }

    /**
     * Close the form modal without saving.
     */
    hideFormModal(): void {
        if (this.hasFormModalTarget) {
            this.formModalTarget.classList.add("hidden");
        }
    }

    /**
     * Submit the form modal: POST for add, PUT for edit.
     */
    async submitForm(): Promise<void> {
        if (!this.hasFormInputTarget) {
            return;
        }

        const text = this.formInputTarget.value.trim();
        if (text === "") {
            return;
        }

        let url: string;
        let method: string;

        if (this.editIndex === null) {
            url = this.createUrlValue;
            method = "POST";
        } else {
            url = this.updateUrlTemplateValue.replace("99999", String(this.editIndex));
            method = "PUT";
        }

        const suggestions = await this.sendRequest(url, method, { text });
        if (suggestions !== null) {
            this.refreshSuggestionsList(suggestions);
        }

        this.hideFormModal();
    }

    // ─── Delete confirmation modal ──────────────────────────────

    /**
     * Open the delete confirmation modal.
     */
    confirmDelete(event: Event): void {
        const button = event.currentTarget as HTMLButtonElement;
        this.deleteIndex = parseInt(button.dataset.index || "0", 10);

        if (this.hasDeleteModalTarget) {
            this.deleteModalTarget.classList.remove("hidden");
        }
    }

    /**
     * Close the delete confirmation modal without deleting.
     */
    cancelDelete(): void {
        this.deleteIndex = null;
        if (this.hasDeleteModalTarget) {
            this.deleteModalTarget.classList.add("hidden");
        }
    }

    /**
     * Execute the deletion after confirmation.
     */
    async executeDelete(): Promise<void> {
        if (this.deleteIndex == null) {
            return;
        }

        const url = this.deleteUrlTemplateValue.replace("99999", String(this.deleteIndex));
        const suggestions = await this.sendRequest(url, "DELETE");

        if (suggestions !== null) {
            this.refreshSuggestionsList(suggestions);
        }

        this.cancelDelete();
    }

    // ─── Private helpers ────────────────────────────────────────

    private openFormModal(text: string, title: string): void {
        if (!this.hasFormModalTarget || !this.hasFormInputTarget) {
            return;
        }

        if (this.hasFormTitleTarget) {
            this.formTitleTarget.textContent = title;
        }

        this.formInputTarget.value = text;
        this.formInputTarget.placeholder = this.placeholderValue;
        this.formModalTarget.classList.remove("hidden");

        // Focus the textarea after it becomes visible
        requestAnimationFrame(() => {
            this.formInputTarget.focus();
        });
    }

    /**
     * Send a JSON request to the API and return the updated suggestions list.
     * Returns null if the request failed.
     */
    private async sendRequest(url: string, method: string, body?: Record<string, string>): Promise<string[] | null> {
        try {
            const options: RequestInit = {
                method,
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-Token": this.csrfTokenValue,
                },
            };

            if (body) {
                options.body = JSON.stringify(body);
            }

            const response = await fetch(url, options);

            if (!response.ok) {
                return null;
            }

            const data = (await response.json()) as {
                suggestions?: string[];
            };

            return data.suggestions ?? null;
        } catch {
            return null;
        }
    }

    /**
     * Rebuild the suggestions list DOM from the server response.
     */
    refreshSuggestionsList(suggestions: string[]): void {
        if (!this.hasSuggestionListTarget) {
            return;
        }

        const container = this.suggestionListTarget;
        container.innerHTML = "";

        suggestions.forEach((text, index) => {
            const row = document.createElement("div");
            row.className = "group flex items-start gap-1";
            row.dataset.index = String(index);

            const button = document.createElement("button");
            button.type = "button";
            button.dataset.text = text;
            button.dataset.promptSuggestionsTarget = "suggestion";
            button.dataset.action = [
                "click->prompt-suggestions#insert",
                "mouseenter->prompt-suggestions#hoverStart",
                "mouseleave->prompt-suggestions#hoverEnd",
            ].join(" ");
            button.className =
                "prompt-suggestion flex-1 px-3 py-1.5 text-xs border border-dark-300 dark:border-dark-600 text-dark-600 dark:text-dark-400 hover:bg-dark-100 dark:hover:bg-dark-700 hover:text-dark-900 dark:hover:text-dark-100 cursor-pointer";
            if (index >= this.maxVisibleValue) {
                button.classList.add("hidden");
            }
            button.textContent = text;

            const actions = document.createElement("div");
            actions.className =
                "flex-shrink-0 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity";
            if (index >= this.maxVisibleValue) {
                actions.classList.add("hidden");
            }

            // Edit button
            const editBtn = document.createElement("button");
            editBtn.type = "button";
            editBtn.dataset.action = "click->prompt-suggestions#showEditModal";
            editBtn.dataset.index = String(index);
            editBtn.dataset.text = text;
            editBtn.className =
                "p-1 text-dark-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors";
            editBtn.innerHTML =
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z" /><path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z" /></svg>';

            // Delete button
            const deleteBtn = document.createElement("button");
            deleteBtn.type = "button";
            deleteBtn.dataset.action = "click->prompt-suggestions#confirmDelete";
            deleteBtn.dataset.index = String(index);
            deleteBtn.className = "p-1 text-dark-400 hover:text-red-600 dark:hover:text-red-400 transition-colors";
            deleteBtn.innerHTML =
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3.5 h-3.5"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>';

            actions.appendChild(editBtn);
            actions.appendChild(deleteBtn);

            row.appendChild(button);
            row.appendChild(actions);

            container.appendChild(row);
        });

        // Update expand/collapse buttons based on the new suggestion count
        this.updateExpandCollapseState(suggestions.length);
    }

    /**
     * Show/hide and update the expand/collapse buttons based on the current suggestion count.
     * Resets to collapsed state so new items beyond maxVisible are hidden by default.
     */
    updateExpandCollapseState(totalCount: number): void {
        if (!this.hasExpandCollapseWrapperTarget) {
            return;
        }

        const hiddenCount = totalCount - this.maxVisibleValue;

        if (hiddenCount > 0) {
            this.expandCollapseWrapperTarget.classList.remove("hidden");

            // Reset to collapsed state
            if (this.hasExpandButtonTarget) {
                this.expandButtonTarget.classList.remove("hidden");
                this.expandButtonTarget.textContent = this.showMoreTemplateValue.replace(
                    "{count}",
                    String(hiddenCount),
                );
            }
            if (this.hasCollapseButtonTarget) {
                this.collapseButtonTarget.classList.add("hidden");
                this.collapseButtonTarget.textContent = this.showLessLabelValue;
            }
        } else {
            this.expandCollapseWrapperTarget.classList.add("hidden");
        }
    }
}
