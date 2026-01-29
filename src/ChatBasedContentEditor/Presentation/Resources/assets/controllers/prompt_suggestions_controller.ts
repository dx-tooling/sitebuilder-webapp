import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for prompt suggestions.
 * Displays clickable suggestion buttons that dispatch events for insertion into the chat input.
 */
export default class extends Controller {
    static targets = ["suggestion", "expandButton", "collapseButton"];
    static values = {
        maxVisible: { type: Number, default: 3 },
    };

    declare readonly suggestionTargets: HTMLButtonElement[];
    declare readonly hasExpandButtonTarget: boolean;
    declare readonly expandButtonTarget: HTMLButtonElement;
    declare readonly hasCollapseButtonTarget: boolean;
    declare readonly collapseButtonTarget: HTMLButtonElement;
    declare readonly maxVisibleValue: number;

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
     * Show all hidden suggestions and toggle expand/collapse buttons.
     */
    expand(): void {
        // Show all hidden suggestions
        this.suggestionTargets.forEach((button) => {
            button.classList.remove("hidden");
        });

        // Hide expand button, show collapse button
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
        // Hide suggestions beyond maxVisible
        this.suggestionTargets.forEach((button, index) => {
            if (index >= this.maxVisibleValue) {
                button.classList.add("hidden");
            }
        });

        // Show expand button, hide collapse button
        if (this.hasExpandButtonTarget) {
            this.expandButtonTarget.classList.remove("hidden");
        }
        if (this.hasCollapseButtonTarget) {
            this.collapseButtonTarget.classList.add("hidden");
        }
    }
}
