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
        readOnly: { type: Boolean, default: false },
        photoBuilderUrlPattern: { type: String, default: "" },
        photoBuilderLabel: { type: String, default: "Generate matching images" },
        editHtmlLabel: { type: String, default: "Edit HTML" },
        previewLabel: { type: String, default: "Preview" },
    };

    static targets = ["list", "container", "photoBuilderSection", "photoBuilderLinks"];

    declare readonly pollUrlValue: string;
    declare readonly pollIntervalValue: number;
    declare readonly readOnlyValue: boolean;
    declare readonly photoBuilderUrlPatternValue: string;
    declare readonly photoBuilderLabelValue: string;
    declare readonly editHtmlLabelValue: string;
    declare readonly previewLabelValue: string;

    declare readonly hasListTarget: boolean;
    declare readonly listTarget: HTMLElement;
    declare readonly hasContainerTarget: boolean;
    declare readonly containerTarget: HTMLElement;
    declare readonly hasPhotoBuilderSectionTarget: boolean;
    declare readonly photoBuilderSectionTarget: HTMLElement;
    declare readonly hasPhotoBuilderLinksTarget: boolean;
    declare readonly photoBuilderLinksTarget: HTMLElement;

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
        this.renderFileList(files);
        this.renderPhotoBuilderLinks(files);
    }

    private renderFileList(files: DistFile[]): void {
        this.listTarget.innerHTML = "";

        for (const file of files) {
            const li = document.createElement("li");
            li.className = "flex items-center justify-between gap-3 py-2 group";

            // Left side: document icon + filename
            const nameWrapper = document.createElement("div");
            nameWrapper.className = "flex items-center gap-2 min-w-0";
            nameWrapper.innerHTML =
                `<svg class="w-4 h-4 flex-shrink-0 text-dark-400 dark:text-dark-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">` +
                `<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />` +
                `</svg>`;

            const fileName = document.createElement("a");
            fileName.href = file.url;
            fileName.target = "_blank";
            fileName.className =
                "text-sm text-dark-700 dark:text-dark-300 truncate " +
                "hover:text-primary-600 dark:hover:text-primary-400 transition-colors duration-150";
            fileName.textContent = file.path;
            nameWrapper.appendChild(fileName);
            li.appendChild(nameWrapper);

            // Right side: action buttons
            const actions = document.createElement("div");
            actions.className = "flex items-center gap-1 flex-shrink-0";

            // Edit button - only in edit mode
            if (!this.readOnlyValue) {
                const editLink = document.createElement("a");
                editLink.href = "#";
                editLink.title = this.editHtmlLabelValue;
                editLink.className =
                    "inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium " +
                    "text-dark-500 hover:text-dark-700 hover:bg-dark-100 " +
                    "dark:text-dark-400 dark:hover:text-dark-200 dark:hover:bg-dark-700/50 " +
                    "transition-colors duration-150";
                editLink.innerHTML =
                    `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">` +
                    `<path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />` +
                    `</svg>` +
                    `<span>${this.editHtmlLabelValue}</span>`;
                editLink.addEventListener("click", (e) => {
                    e.preventDefault();
                    const fullPath = file.url.split("/").slice(3).join("/");
                    this.openHtmlEditor(fullPath);
                });
                actions.appendChild(editLink);
            }

            // Preview button
            const previewLink = document.createElement("a");
            previewLink.href = file.url;
            previewLink.target = "_blank";
            previewLink.title = this.previewLabelValue;
            previewLink.className =
                "inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium " +
                "text-primary-600 hover:text-primary-700 hover:bg-primary-50 " +
                "dark:text-primary-400 dark:hover:text-primary-300 dark:hover:bg-primary-900/20 " +
                "transition-colors duration-150";
            previewLink.innerHTML =
                `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">` +
                `<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />` +
                `</svg>` +
                `<span>${this.previewLabelValue}</span>`;
            actions.appendChild(previewLink);

            li.appendChild(actions);
            this.listTarget.appendChild(li);
        }
    }

    private renderPhotoBuilderLinks(files: DistFile[]): void {
        if (!this.hasPhotoBuilderSectionTarget || !this.hasPhotoBuilderLinksTarget) {
            return;
        }

        if (!this.photoBuilderUrlPatternValue || this.readOnlyValue || files.length === 0) {
            this.photoBuilderSectionTarget.classList.add("hidden");

            return;
        }

        this.photoBuilderSectionTarget.classList.remove("hidden");
        this.photoBuilderLinksTarget.innerHTML = "";

        for (const file of files) {
            const link = document.createElement("a");
            link.href = this.photoBuilderUrlPatternValue.replace("__PAGE_PATH__", encodeURIComponent(file.path));
            link.title = this.photoBuilderLabelValue;
            link.className =
                "inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium " +
                "bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-700/10 " +
                "hover:bg-purple-100 hover:text-purple-800 " +
                "dark:bg-purple-900/20 dark:text-purple-300 dark:ring-purple-400/30 " +
                "dark:hover:bg-purple-900/40 dark:hover:text-purple-200 " +
                "transition-colors duration-150";
            link.innerHTML =
                `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">` +
                `<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />` +
                `</svg>` +
                `<span>${file.path}</span>`;

            this.photoBuilderLinksTarget.appendChild(link);
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
