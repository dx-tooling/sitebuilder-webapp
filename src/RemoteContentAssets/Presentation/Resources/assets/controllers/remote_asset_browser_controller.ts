import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for browsing remote content assets.
 * Features:
 * - Fetches asset URLs from configured manifest endpoint
 * - Search/filter by full URL (domain, path, filename)
 * - Scrollable list with configurable visible window size
 * - Image preview for image URLs
 * - Click to open asset in new tab
 * - Add to chat button dispatches custom event for insertion
 * - Drag-and-drop upload to S3 (when configured, supports multiple files)
 * - Clickable dropzone to open file dialog (supports multiple files)
 */
export default class extends Controller {
    static values = {
        fetchUrl: String,
        windowSize: { type: Number, default: 20 },
        addToChatLabel: { type: String, default: "Add to chat" },
        openInNewTabLabel: { type: String, default: "Open in new tab" },
        // Upload configuration (optional - if not set, upload is disabled)
        uploadUrl: { type: String, default: "" },
        uploadCsrfToken: { type: String, default: "" },
        workspaceId: { type: String, default: "" },
    };

    static targets = [
        "list",
        "count",
        "loading",
        "empty",
        "search",
        "dropzone",
        "fileInput",
        "uploadProgress",
        "uploadProgressText",
        "uploadError",
        "uploadSuccess",
    ];

    declare readonly fetchUrlValue: string;
    declare readonly windowSizeValue: number;
    declare readonly addToChatLabelValue: string;
    declare readonly openInNewTabLabelValue: string;
    declare readonly uploadUrlValue: string;
    declare readonly uploadCsrfTokenValue: string;
    declare readonly workspaceIdValue: string;

    declare readonly hasListTarget: boolean;
    declare readonly listTarget: HTMLElement;
    declare readonly hasCountTarget: boolean;
    declare readonly countTarget: HTMLElement;
    declare readonly hasLoadingTarget: boolean;
    declare readonly loadingTarget: HTMLElement;
    declare readonly hasEmptyTarget: boolean;
    declare readonly emptyTarget: HTMLElement;
    declare readonly hasSearchTarget: boolean;
    declare readonly searchTarget: HTMLInputElement;
    declare readonly hasDropzoneTarget: boolean;
    declare readonly dropzoneTarget: HTMLElement;
    declare readonly hasFileInputTarget: boolean;
    declare readonly fileInputTarget: HTMLInputElement;
    declare readonly hasUploadProgressTarget: boolean;
    declare readonly uploadProgressTarget: HTMLElement;
    declare readonly hasUploadProgressTextTarget: boolean;
    declare readonly uploadProgressTextTarget: HTMLElement;
    declare readonly hasUploadErrorTarget: boolean;
    declare readonly uploadErrorTarget: HTMLElement;
    declare readonly hasUploadSuccessTarget: boolean;
    declare readonly uploadSuccessTarget: HTMLElement;

    private urls: string[] = [];
    private filteredUrls: string[] = [];
    private itemHeight: number = 80;
    private isLoading: boolean = false;
    private isUploading: boolean = false;

    connect(): void {
        this.fetchAssets();
        this.setupDropzone();
    }

    /**
     * Set up drag-and-drop event handlers if upload is configured.
     */
    private setupDropzone(): void {
        if (!this.isUploadEnabled() || !this.hasDropzoneTarget) {
            return;
        }

        const dropzone = this.dropzoneTarget;

        dropzone.addEventListener("dragover", (e) => this.handleDragOver(e));
        dropzone.addEventListener("dragleave", (e) => this.handleDragLeave(e));
        dropzone.addEventListener("drop", (e) => this.handleDrop(e));
    }

    /**
     * Check if upload functionality is enabled.
     */
    private isUploadEnabled(): boolean {
        return this.uploadUrlValue !== "" && this.uploadCsrfTokenValue !== "" && this.workspaceIdValue !== "";
    }

    /**
     * Handle dragover event - show visual feedback.
     */
    private handleDragOver(e: DragEvent): void {
        e.preventDefault();
        e.stopPropagation();

        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.add("border-primary-500", "bg-primary-50", "dark:bg-primary-900/20");
        }
    }

    /**
     * Handle dragleave event - remove visual feedback.
     */
    private handleDragLeave(e: DragEvent): void {
        e.preventDefault();
        e.stopPropagation();

        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.remove("border-primary-500", "bg-primary-50", "dark:bg-primary-900/20");
        }
    }

    /**
     * Handle drop event - upload all dropped files.
     */
    private async handleDrop(e: DragEvent): Promise<void> {
        e.preventDefault();
        e.stopPropagation();

        // Remove visual feedback
        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.remove("border-primary-500", "bg-primary-50", "dark:bg-primary-900/20");
        }

        const files = e.dataTransfer?.files;
        if (!files || files.length === 0) {
            return;
        }

        await this.uploadFiles(files);
    }

    /**
     * Open the native file dialog by clicking the hidden file input.
     */
    openFileDialog(): void {
        if (!this.isUploadEnabled() || !this.hasFileInputTarget || this.isUploading) {
            return;
        }

        // Reset value so the same file(s) can be re-selected
        this.fileInputTarget.value = "";
        this.fileInputTarget.click();
    }

    /**
     * Handle file selection from the native file dialog.
     */
    handleFileSelect(e: Event): void {
        const input = e.target as HTMLInputElement;
        const files = input.files;
        if (!files || files.length === 0) {
            return;
        }

        void this.uploadFiles(files);
    }

    /**
     * Upload multiple files sequentially to S3.
     */
    private async uploadFiles(files: FileList): Promise<void> {
        if (!this.isUploadEnabled() || this.isUploading) {
            return;
        }

        this.isUploading = true;
        const total = files.length;
        let successCount = 0;
        let errorCount = 0;

        for (let i = 0; i < total; i++) {
            this.updateUploadProgressText(i + 1, total);
            this.showUploadStatus("progress");

            try {
                const uploaded = await this.uploadSingleFile(files[i]);
                if (uploaded) {
                    successCount++;
                } else {
                    errorCount++;
                }
            } catch {
                errorCount++;
            }
        }

        // Re-fetch the asset list once after all uploads
        if (successCount > 0) {
            await this.fetchAssets();
        }

        // Show final status
        if (errorCount > 0 && successCount === 0) {
            this.showUploadError("Upload failed. Please try again.");
        } else if (errorCount > 0) {
            this.showUploadError(`${errorCount} of ${total} uploads failed.`);
        } else {
            this.showUploadStatus("success");
            setTimeout(() => this.showUploadStatus("none"), 3000);
        }

        this.isUploading = false;
    }

    /**
     * Upload a single file to S3. Returns true on success.
     */
    private async uploadSingleFile(file: File): Promise<boolean> {
        const formData = new FormData();
        formData.append("file", file);
        formData.append("workspace_id", this.workspaceIdValue);
        formData.append("_csrf_token", this.uploadCsrfTokenValue);

        const response = await fetch(this.uploadUrlValue, {
            method: "POST",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
            body: formData,
        });

        const data = (await response.json()) as { success?: boolean; url?: string; error?: string };

        if (data.success && data.url) {
            this.dispatch("uploadComplete", { detail: { url: data.url } });

            return true;
        }

        return false;
    }

    /**
     * Update the upload progress text for multi-file uploads.
     */
    private updateUploadProgressText(current: number, total: number): void {
        if (!this.hasUploadProgressTextTarget) {
            return;
        }

        if (total === 1) {
            this.uploadProgressTextTarget.textContent = "";
        } else {
            this.uploadProgressTextTarget.textContent = `(${current}/${total})`;
        }
    }

    /**
     * Show upload status indicator.
     */
    private showUploadStatus(which: "progress" | "success" | "error" | "none"): void {
        if (this.hasUploadProgressTarget) {
            this.uploadProgressTarget.classList.toggle("hidden", which !== "progress");
        }
        if (this.hasUploadSuccessTarget) {
            this.uploadSuccessTarget.classList.toggle("hidden", which !== "success");
        }
        if (this.hasUploadErrorTarget) {
            this.uploadErrorTarget.classList.toggle("hidden", which !== "error");
        }
    }

    /**
     * Show upload error with message.
     */
    private showUploadError(message: string): void {
        if (this.hasUploadErrorTarget) {
            const textEl = this.uploadErrorTarget.querySelector("[data-error-text]");
            if (textEl) {
                textEl.textContent = message;
            }
        }
        this.showUploadStatus("error");
        // Auto-hide error message after 5 seconds
        setTimeout(() => this.showUploadStatus("none"), 5000);
    }

    private async fetchAssets(): Promise<void> {
        if (this.isLoading || !this.fetchUrlValue) {
            return;
        }

        this.isLoading = true;
        this.showLoading(true);

        try {
            const response = await fetch(this.fetchUrlValue, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = (await response.json()) as { urls?: string[] };
            this.urls = data.urls ?? [];

            this.showLoading(false);

            if (this.urls.length === 0) {
                this.filteredUrls = [];
                this.updateCount();
                this.showEmpty(true);
            } else {
                // Re-apply current search filter (if any) to the new URL list
                this.filter();
                this.showEmpty(this.filteredUrls.length === 0);
            }
        } catch {
            this.showLoading(false);
            this.showEmpty(true);
        } finally {
            this.isLoading = false;
        }
    }

    private showLoading(show: boolean): void {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.toggle("hidden", !show);
        }
    }

    private showEmpty(show: boolean): void {
        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle("hidden", !show);
        }
    }

    private updateCount(): void {
        if (this.hasCountTarget) {
            if (this.filteredUrls.length === this.urls.length) {
                this.countTarget.textContent = `(${this.urls.length})`;
            } else {
                this.countTarget.textContent = `(${this.filteredUrls.length}/${this.urls.length})`;
            }
        }
    }

    /**
     * Filter assets by search query. Triggered by input event on search field.
     */
    filter(): void {
        if (!this.hasSearchTarget) {
            return;
        }

        const query = this.searchTarget.value.trim().toLowerCase();

        if (query === "") {
            this.filteredUrls = this.urls;
        } else {
            this.filteredUrls = this.urls.filter((url) => url.toLowerCase().includes(query));
        }

        this.updateCount();
        this.renderItems();
    }

    private renderItems(): void {
        if (!this.hasListTarget) {
            return;
        }

        // Set max-height to show exactly windowSize items
        this.listTarget.style.maxHeight = `${this.windowSizeValue * this.itemHeight}px`;
        this.listTarget.style.overflowY = "auto";

        // Clear list
        this.listTarget.innerHTML = "";

        // Show empty state if no filtered results
        if (this.filteredUrls.length === 0) {
            this.showEmpty(true);

            return;
        }

        this.showEmpty(false);

        // Render filtered items
        for (const url of this.filteredUrls) {
            const itemEl = this.createAssetItem(url);
            this.listTarget.appendChild(itemEl);
        }
    }

    private createAssetItem(url: string): HTMLElement {
        const item = document.createElement("div");
        item.className = "flex items-center gap-3 p-2 hover:bg-dark-50 dark:hover:bg-dark-800 rounded cursor-pointer";
        item.style.height = `${this.itemHeight}px`;

        // Clicking anywhere on the row adds to chat
        item.addEventListener("click", () => {
            this.addToChat(url);
        });

        // Preview container (clickable link to open URL)
        const previewLink = document.createElement("a");
        previewLink.href = url;
        previewLink.target = "_blank";
        previewLink.rel = "noopener noreferrer";
        previewLink.className =
            "w-12 h-12 flex-shrink-0 rounded bg-dark-100 dark:bg-dark-700 overflow-hidden flex items-center justify-center";
        previewLink.title = this.openInNewTabLabelValue;
        previewLink.addEventListener("click", (e) => {
            e.stopPropagation(); // Prevent row click from firing
        });

        if (this.isImageUrl(url)) {
            const img = document.createElement("img");
            img.src = url;
            img.className = "w-full h-full object-cover";
            img.loading = "lazy";
            img.alt = "";
            img.onerror = () => {
                img.replaceWith(this.createFileIcon());
            };
            previewLink.appendChild(img);
        } else {
            previewLink.appendChild(this.createFileIcon());
        }

        // Filename container (flex-1 to take remaining space): filename + URL prefix line
        const filenameContainer = document.createElement("div");
        filenameContainer.className = "flex-1 min-w-0 flex flex-col gap-0.5";

        const filenameLink = document.createElement("a");
        filenameLink.href = url;
        filenameLink.target = "_blank";
        filenameLink.rel = "noopener noreferrer";
        filenameLink.className =
            "truncate text-sm text-primary-600 dark:text-primary-400 hover:underline inline-block max-w-full";
        filenameLink.textContent = this.extractFilename(url);
        filenameLink.title = url;
        filenameLink.addEventListener("click", (e) => {
            e.stopPropagation(); // Prevent row click from firing
        });

        const urlPrefix = this.urlWithoutFilename(url);
        const urlPrefixEl = document.createElement("div");
        urlPrefixEl.setAttribute("data-remote-asset-url-prefix", "");
        urlPrefixEl.className = "truncate text-xs text-dark-400 dark:text-dark-500 block max-w-full";
        urlPrefixEl.textContent = urlPrefix;
        urlPrefixEl.title = urlPrefix;

        filenameContainer.appendChild(filenameLink);
        filenameContainer.appendChild(urlPrefixEl);

        // Add to chat button
        const addButton = document.createElement("button");
        addButton.type = "button";
        addButton.className = "p-1.5 text-dark-400 hover:text-primary-600 dark:hover:text-primary-400 flex-shrink-0";
        addButton.title = this.addToChatLabelValue;
        addButton.innerHTML = `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>`;
        // Button click also adds to chat (same as row click, but explicit)
        addButton.addEventListener("click", (e) => {
            e.stopPropagation();
            this.addToChat(url);
        });

        item.appendChild(previewLink);
        item.appendChild(filenameContainer);
        item.appendChild(addButton);

        return item;
    }

    private createFileIcon(): SVGSVGElement {
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "w-6 h-6 text-dark-400");
        svg.setAttribute("fill", "none");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("stroke", "currentColor");
        svg.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />`;

        return svg;
    }

    private isImageUrl(url: string): boolean {
        const imageExtensions = [".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".avif"];
        const lowercaseUrl = url.toLowerCase();

        return imageExtensions.some((ext) => lowercaseUrl.endsWith(ext));
    }

    private extractFilename(url: string): string {
        try {
            const urlObj = new URL(url);
            const pathname = urlObj.pathname;
            const segments = pathname.split("/").filter(Boolean);

            return segments[segments.length - 1] || url;
        } catch {
            // If URL parsing fails, try simple split
            const segments = url.split("/").filter(Boolean);

            return segments[segments.length - 1] || url;
        }
    }

    /**
     * Base URL (scheme + host + path without filename), with trailing slash.
     * Used to display the folder/context beneath the filename in the list.
     */
    private urlWithoutFilename(url: string): string {
        try {
            const urlObj = new URL(url);
            const pathname = urlObj.pathname;
            const segments = pathname.split("/").filter(Boolean);

            if (segments.length <= 1) {
                return urlObj.origin + (pathname.endsWith("/") ? pathname : pathname + "/");
            }

            const pathWithoutLast = "/" + segments.slice(0, -1).join("/") + "/";

            return urlObj.origin + pathWithoutLast;
        } catch {
            return url;
        }
    }

    private addToChat(url: string): void {
        // Dispatch custom event that chat controller listens for
        this.dispatch("insert", { detail: { url } });
    }
}
