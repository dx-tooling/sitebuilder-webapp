import { Controller } from "@hotwired/stimulus";

interface DistFile {
    path: string;
    url: string;
}

interface DistFilesResponse {
    files: DistFile[];
    error?: string;
}

interface HtmlEditorOpenEvent {
    path: string;
}

/**
 * Stimulus controller for polling and displaying dist files.
 *
 * Uses non-overlapping polling: next poll is scheduled only after the
 * current one completes, preventing request pile-up on slow connections.
 */
export default class extends Controller {
    static values = {
        pollUrl: String,
        pollInterval: { type: Number, default: 3000 },
    };

    static targets = ["list", "container"];

    declare readonly pollUrlValue: string;
    declare readonly pollIntervalValue: number;

    declare readonly hasListTarget: boolean;
    declare readonly listTarget: HTMLElement;
    declare readonly hasContainerTarget: boolean;
    declare readonly containerTarget: HTMLElement;

    private pollingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private lastFilesJson: string = "";
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
            this.pollingTimeoutId = setTimeout(() => this.poll(), this.pollIntervalValue);
        }
    }

    private async poll(): Promise<void> {
        try {
            const response = await fetch(this.pollUrlValue, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (response.ok) {
                const data = (await response.json()) as DistFilesResponse;
                const filesJson = JSON.stringify(data.files);

                // Only update if files changed
                if (filesJson !== this.lastFilesJson) {
                    this.lastFilesJson = filesJson;
                    this.renderFiles(data.files);
                }
            }
        } catch {
            // Silently ignore polling errors
        }

        this.scheduleNextPoll();
    }

    private renderFiles(files: DistFile[]): void {
        if (!this.hasListTarget || !this.hasContainerTarget) {
            return;
        }

        if (files.length === 0) {
            this.containerTarget.classList.add("hidden");

            return;
        }

        this.containerTarget.classList.remove("hidden");
        this.listTarget.innerHTML = "";

        for (const file of files) {
            const li = document.createElement("li");
            const span = document.createElement("span");
            span.className = "flex items-center space-x-2 whitespace-nowrap";

            // Create edit link
            const editLink = document.createElement("a");
            editLink.href = "#";
            editLink.className =
                "text-dark-500 hover:text-primary-600 dark:text-dark-400 dark:hover:text-primary-400 transition-colors";
            editLink.title = "Edit HTML";
            editLink.innerHTML = `
                <svg width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                    <path d="M13.877 3.123l3.001 3.002.5-.5a2.123 2.123 0 10-3.002-3.002l-.5.5zM15.5 7.5l-3.002-3.002-9.524 9.525L2 17.999l3.976-.974L15.5 7.5z"/>
                </svg>
            `;
            editLink.addEventListener("click", (e) => {
                e.preventDefault();
                this.openHtmlEditor(file.path);
            });

            // Create preview link
            const previewLink = document.createElement("a");
            previewLink.href = file.url;
            previewLink.target = "_blank";
            previewLink.className =
                "text-dark-500 hover:text-primary-600 dark:text-dark-400 dark:hover:text-primary-400 transition-colors";
            previewLink.title = "Open preview";
            previewLink.innerHTML = `
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                </svg>
            `;

            // Create file path text
            const pathText = document.createElement("span");
            pathText.className = "text-dark-700 dark:text-dark-300";
            pathText.textContent = file.path;

            span.appendChild(editLink);
            span.appendChild(previewLink);
            span.appendChild(pathText);
            li.appendChild(span);
            this.listTarget.appendChild(li);
        }
    }

    private openHtmlEditor(path: string): void {
        const event = new CustomEvent<HtmlEditorOpenEvent>("html-editor:open", {
            bubbles: true,
            detail: { path },
        });
        this.element.dispatchEvent(event);
    }
}
