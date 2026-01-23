import { Controller } from "@hotwired/stimulus";

interface StatusResponse {
    status: string;
    ready: boolean;
    error: boolean;
}

/**
 * Stimulus controller for polling workspace setup status.
 * Automatically redirects when workspace becomes ready.
 *
 * Uses non-overlapping polling: next poll is scheduled only after the
 * current one completes, preventing request pile-up on slow connections.
 */
export default class extends Controller {
    static values = {
        pollUrl: String,
        redirectUrl: String,
    };

    static targets = ["spinner", "errorIcon", "title", "message", "status", "actions"];

    declare readonly pollUrlValue: string;
    declare readonly redirectUrlValue: string;

    declare readonly hasSpinnerTarget: boolean;
    declare readonly spinnerTarget: HTMLElement;
    declare readonly hasErrorIconTarget: boolean;
    declare readonly errorIconTarget: HTMLElement;
    declare readonly hasTitleTarget: boolean;
    declare readonly titleTarget: HTMLElement;
    declare readonly hasMessageTarget: boolean;
    declare readonly messageTarget: HTMLElement;
    declare readonly hasStatusTarget: boolean;
    declare readonly statusTarget: HTMLElement;
    declare readonly hasActionsTarget: boolean;
    declare readonly actionsTarget: HTMLElement;

    private pollingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private pollCount: number = 0;
    private readonly maxPollCount: number = 120; // 2 minutes at 1 second interval
    private isActive: boolean = false;

    connect(): void {
        this.isActive = true;
        this.poll();
    }

    disconnect(): void {
        this.isActive = false;
        this.stopPolling();
    }

    private stopPolling(): void {
        if (this.pollingTimeoutId !== null) {
            clearTimeout(this.pollingTimeoutId);
            this.pollingTimeoutId = null;
        }
    }

    private scheduleNextPoll(): void {
        if (this.isActive) {
            this.pollingTimeoutId = setTimeout(() => this.poll(), 1000);
        }
    }

    private async poll(): Promise<void> {
        this.pollCount++;

        // Stop polling if we've exceeded the max
        if (this.pollCount > this.maxPollCount) {
            this.isActive = false;
            this.showError("This is taking longer than expected. Please try again.");

            return;
        }

        try {
            const response = await fetch(this.pollUrlValue, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (!response.ok) {
                // Keep polling on server errors - setup might still be running
                this.scheduleNextPoll();

                return;
            }

            const data = (await response.json()) as StatusResponse;

            // Update status display
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = data.status.replace(/_/g, " ").toLowerCase();
            }

            if (data.ready) {
                // Workspace is ready - redirect to start conversation
                this.isActive = false;
                window.location.href = this.redirectUrlValue;

                return;
            }

            if (data.error) {
                // Setup failed - show error state
                this.isActive = false;
                this.showError("We couldn't finish setup. Please try resetting the work area.");

                return;
            }
        } catch {
            // Network error - keep polling, might be temporary
        }

        this.scheduleNextPoll();
    }

    private showError(message: string): void {
        // Update UI to error state
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add("hidden");
        }

        if (this.hasErrorIconTarget) {
            this.errorIconTarget.classList.remove("hidden");
        }

        if (this.hasTitleTarget) {
            this.titleTarget.textContent = "We hit a snag";
            this.titleTarget.classList.remove("text-blue-800", "dark:text-blue-200");
            this.titleTarget.classList.add("text-red-800", "dark:text-red-200");
        }

        if (this.hasMessageTarget) {
            this.messageTarget.textContent = message;
            this.messageTarget.classList.remove("text-blue-700", "dark:text-blue-300");
            this.messageTarget.classList.add("text-red-700", "dark:text-red-300");
        }

        if (this.hasActionsTarget) {
            this.actionsTarget.classList.remove("hidden");
        }

        // Update parent container styling
        const container = this.element.querySelector(".bg-blue-50");
        if (container) {
            container.classList.remove("bg-blue-50", "dark:bg-blue-900/20", "border-blue-200", "dark:border-blue-800");
            container.classList.add("bg-red-50", "dark:bg-red-900/20", "border-red-200", "dark:border-red-800");
        }
    }
}
