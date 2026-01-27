import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for browsing remote content assets.
 * Features:
 * - Fetches asset URLs from configured manifest endpoint
 * - Search/filter by filename
 * - Scrollable list with configurable visible window size
 * - Image preview for image URLs
 * - Click to open asset in new tab
 * - Add to chat button dispatches custom event for insertion
 */
export default class extends Controller {
    static values = {
        fetchUrl: String,
        windowSize: { type: Number, default: 20 },
        addToChatLabel: { type: String, default: "Add to chat" },
        openInNewTabLabel: { type: String, default: "Open in new tab" },
    };

    static targets = ["list", "count", "loading", "empty", "search"];

    declare readonly fetchUrlValue: string;
    declare readonly windowSizeValue: number;
    declare readonly addToChatLabelValue: string;
    declare readonly openInNewTabLabelValue: string;

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

    private urls: string[] = [];
    private filteredUrls: string[] = [];
    private itemHeight: number = 64;
    private isLoading: boolean = false;

    connect(): void {
        this.fetchAssets();
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
            this.filteredUrls = this.urls;

            this.updateCount();
            this.showLoading(false);

            if (this.urls.length === 0) {
                this.showEmpty(true);
            } else {
                this.showEmpty(false);
                this.renderItems();
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
            this.filteredUrls = this.urls.filter((url) => {
                const filename = this.extractFilename(url).toLowerCase();

                return filename.includes(query);
            });
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

        // Filename container (flex-1 to take remaining space, but link only wraps text)
        const filenameContainer = document.createElement("div");
        filenameContainer.className = "flex-1 min-w-0"; // min-w-0 allows truncation to work

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

        filenameContainer.appendChild(filenameLink);

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

    private addToChat(url: string): void {
        // Dispatch custom event that chat controller listens for
        this.dispatch("insert", { detail: { url } });
    }
}
