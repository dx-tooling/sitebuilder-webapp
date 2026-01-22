import { Controller } from "@hotwired/stimulus";

interface StatusResponse {
    status: string;
    ready: boolean;
    error: boolean;
}

/**
 * Stimulus controller for polling workspace setup status.
 * Automatically redirects when workspace becomes ready.
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

    private pollingIntervalId: ReturnType<typeof setInterval> | null = null;
    private pollCount: number = 0;
    private readonly maxPollCount: number = 120; // 2 minutes at 1 second interval

    connect(): void {
        this.startPolling();
    }

    disconnect(): void {
        this.stopPolling();
    }

    private startPolling(): void {
        // Poll immediately, then every second
        this.poll();
        this.pollingIntervalId = setInterval(() => this.poll(), 1000);
    }

    private stopPolling(): void {
        if (this.pollingIntervalId !== null) {
            clearInterval(this.pollingIntervalId);
            this.pollingIntervalId = null;
        }
    }

    private async poll(): Promise<void> {
        this.pollCount++;

        // Stop polling if we've exceeded the max
        if (this.pollCount > this.maxPollCount) {
            this.stopPolling();
            this.showError("Setup is taking longer than expected. Please try again.");

            return;
        }

        try {
            const response = await fetch(this.pollUrlValue, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (!response.ok) {
                // Keep polling on server errors - setup might still be running
                return;
            }

            const data = (await response.json()) as StatusResponse;

            // Update status display
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = data.status.replace(/_/g, " ").toLowerCase();
            }

            if (data.ready) {
                // Workspace is ready - redirect to start conversation
                this.stopPolling();
                window.location.href = this.redirectUrlValue;

                return;
            }

            if (data.error) {
                // Setup failed - show error state
                this.stopPolling();
                this.showError("Workspace setup failed. Please try resetting the workspace.");

                return;
            }
        } catch {
            // Network error - keep polling, might be temporary
        }
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
            this.titleTarget.textContent = "Setup Problem";
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
