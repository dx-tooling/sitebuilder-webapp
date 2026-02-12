import { Controller } from "@hotwired/stimulus";

interface SessionResponse {
    sessionId?: string;
    status: string;
    userPrompt?: string;
    images?: ImageData[];
    error?: string;
}

interface ImageData {
    id: string;
    position: number;
    prompt: string | null;
    suggestedFileName: string | null;
    status: string;
    imageUrl: string | null;
    errorMessage: string | null;
    uploadedToMediaStore?: boolean;
    uploadedFileName?: string | null;
}

interface PromptEditedDetail {
    position: number;
    prompt: string;
}

interface RegenerateRequestedDetail {
    position: number;
    imageId: string;
    prompt: string;
}

interface UploadRequestedDetail {
    position: number;
    imageId: string;
    suggestedFileName: string;
}

const SESSION_ID_PLACEHOLDER = "00000000-0000-0000-0000-000000000000";
const IMAGE_ID_PLACEHOLDER = "00000000-0000-0000-0000-111111111111";
const PROMPT_DEBOUNCE_MS = 400;
const POLL_INTERVAL_ACTIVE_MS = 1000;
const POLL_INTERVAL_IDLE_MS = 5000;

function imageDataEqual(a: ImageData, b: ImageData): boolean {
    return (
        a.id === b.id &&
        a.position === b.position &&
        a.status === b.status &&
        (a.imageUrl ?? null) === (b.imageUrl ?? null) &&
        (a.prompt ?? null) === (b.prompt ?? null) &&
        (a.suggestedFileName ?? null) === (b.suggestedFileName ?? null) &&
        (a.errorMessage ?? null) === (b.errorMessage ?? null) &&
        (a.uploadedToMediaStore ?? false) === (b.uploadedToMediaStore ?? false)
    );
}

/**
 * Orchestrator controller for the PhotoBuilder page.
 *
 * Manages session lifecycle, polling, global state,
 * and coordinates child photo-image controllers via events.
 */
export default class extends Controller {
    static values = {
        createSessionUrl: String,
        pollUrlPattern: String,
        regeneratePromptsUrlPattern: String,
        regenerateImageUrlPattern: String,
        regenerateAllImagesUrlPattern: String,
        updatePromptUrlPattern: String,
        uploadToMediaStoreUrlPattern: String,
        checkManifestAvailabilityUrlPattern: String,
        csrfToken: String,
        workspaceId: String,
        pagePath: String,
        conversationId: String,
        imageCount: Number,
        defaultUserPrompt: String,
        editorUrl: String,
        hasRemoteAssets: Boolean,
        supportsResolutionToggle: Boolean,
        embedPrefillMessage: { type: String, default: "Embed images %fileNames% into page %pagePath%" },
    };

    static targets = [
        "loadingOverlay",
        "mainContent",
        "userPrompt",
        "regeneratePromptsButton",
        "embedButton",
        "imageCard",
        "regeneratingPromptsOverlay",
        "uploadingImagesOverlay",
        "waitingForManifestOverlay",
        "resolutionToggle",
        "loresButton",
        "hiresButton",
    ];

    declare readonly createSessionUrlValue: string;
    declare readonly pollUrlPatternValue: string;
    declare readonly regeneratePromptsUrlPatternValue: string;
    declare readonly regenerateImageUrlPatternValue: string;
    declare readonly regenerateAllImagesUrlPatternValue: string;
    declare readonly updatePromptUrlPatternValue: string;
    declare readonly uploadToMediaStoreUrlPatternValue: string;
    declare readonly checkManifestAvailabilityUrlPatternValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly workspaceIdValue: string;
    declare readonly pagePathValue: string;
    declare readonly conversationIdValue: string;
    declare readonly imageCountValue: number;
    declare readonly defaultUserPromptValue: string;
    declare readonly editorUrlValue: string;
    declare readonly hasRemoteAssetsValue: boolean;
    declare readonly supportsResolutionToggleValue: boolean;
    declare readonly embedPrefillMessageValue: string;

    declare readonly loadingOverlayTarget: HTMLElement;
    declare readonly mainContentTarget: HTMLElement;
    declare readonly userPromptTarget: HTMLTextAreaElement;
    declare readonly regeneratePromptsButtonTarget: HTMLButtonElement;
    declare readonly hasEmbedButtonTarget: boolean;
    declare readonly embedButtonTarget: HTMLButtonElement;
    declare readonly imageCardTargets: HTMLElement[];
    declare readonly hasRegeneratingPromptsOverlayTarget: boolean;
    declare readonly regeneratingPromptsOverlayTarget: HTMLElement;
    declare readonly hasUploadingImagesOverlayTarget: boolean;
    declare readonly uploadingImagesOverlayTarget: HTMLElement;
    declare readonly hasWaitingForManifestOverlayTarget: boolean;
    declare readonly waitingForManifestOverlayTarget: HTMLElement;
    declare readonly hasResolutionToggleTarget: boolean;
    declare readonly resolutionToggleTarget: HTMLElement;
    declare readonly hasLoresButtonTarget: boolean;
    declare readonly loresButtonTarget: HTMLButtonElement;
    declare readonly hasHiresButtonTarget: boolean;
    declare readonly hiresButtonTarget: HTMLButtonElement;

    private sessionId: string | null = null;
    private isRegeneratingPrompts = false;
    private pollingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private isActive = false;
    private anyGenerating = false;
    private lastImages: ImageData[] = [];
    /** Current image size: "1K" = lo-res (default), "2K" = hi-res. */
    private currentImageSize: string = "1K";
    /** Last userPrompt we applied from the server; used to avoid overwriting local edits on poll. */
    private lastAppliedUserPrompt: string | null = null;
    /** Debounce timeouts per imageId for prompt update API calls. */
    private promptDebounceTimeouts: Record<string, ReturnType<typeof setTimeout>> = {};
    /** Last session status from poll; used to choose active vs idle poll interval. */
    private lastPollStatus: string | null = null;

    connect(): void {
        this.isActive = true;
        this.createSession();
    }

    disconnect(): void {
        this.isActive = false;
        this.stopPolling();
        if (this.promptDebounceTimeouts) {
            for (const id of Object.keys(this.promptDebounceTimeouts)) {
                clearTimeout(this.promptDebounceTimeouts[id]);
            }
            this.promptDebounceTimeouts = {};
        }
    }

    private stopPolling(): void {
        if (this.pollingTimeoutId !== null) {
            clearTimeout(this.pollingTimeoutId);
            this.pollingTimeoutId = null;
        }
    }

    /**
     * Schedule the next poll. Uses active interval (1s) when generating, idle interval (5s) when session is images_ready and nothing is generating.
     * Pass explicit intervalMs to force a specific interval (e.g. when user triggers an action and we want to poll soon).
     */
    private scheduleNextPoll(intervalMs?: number): void {
        if (!this.isActive || !this.sessionId) return;
        const idle = (this.lastPollStatus === "images_ready" && !this.anyGenerating) || false;
        const interval = intervalMs ?? (idle ? POLL_INTERVAL_IDLE_MS : POLL_INTERVAL_ACTIVE_MS);
        this.pollingTimeoutId = setTimeout(() => this.poll(), interval);
    }

    /** Switch to active (1s) polling immediately; call when user triggers an action that may change state. */
    private startActivePolling(): void {
        this.stopPolling();
        this.scheduleNextPoll(POLL_INTERVAL_ACTIVE_MS);
    }

    private async createSession(): Promise<void> {
        try {
            const response = await fetch(this.createSessionUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    workspaceId: this.workspaceIdValue,
                    conversationId: this.conversationIdValue,
                    pagePath: this.pagePathValue,
                    userPrompt: this.defaultUserPromptValue,
                }),
            });

            const data = (await response.json()) as SessionResponse;

            if (data.sessionId) {
                this.sessionId = data.sessionId;
                this.poll();
            }
        } catch {
            // Session creation failed - keep loading state
        }
    }

    private async poll(): Promise<void> {
        if (!this.sessionId) return;

        try {
            const url = this.pollUrlPatternValue.replace(SESSION_ID_PLACEHOLDER, this.sessionId);
            const response = await fetch(url, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (response.ok) {
                const data = (await response.json()) as SessionResponse;
                this.handlePollResponse(data);
            }
        } catch {
            // Silently ignore polling errors
        }

        this.scheduleNextPoll();
    }

    private handlePollResponse(data: SessionResponse): void {
        const status = data.status;
        this.lastPollStatus = status;
        const images = data.images || [];
        const prevImages = this.lastImages;
        this.lastImages = images;

        // Hide regenerating-prompts overlay once backend has accepted and we see generating state
        if (
            this.isRegeneratingPrompts &&
            (status === "generating_prompts" ||
                status === "generating_images" ||
                images.some((img) => img.status === "generating" || img.status === "pending"))
        ) {
            this.hideRegeneratingPromptsOverlay();
        }

        // Check if any image is currently generating
        const prevAnyGenerating = this.anyGenerating;
        this.anyGenerating =
            status === "generating_prompts" ||
            status === "generating_images" ||
            images.some((img) => img.status === "generating" || img.status === "pending");

        // Show/hide loading overlay
        if (status === "generating_prompts" && !images.some((img) => img.prompt)) {
            this.loadingOverlayTarget.classList.remove("hidden");
            this.mainContentTarget.classList.add("hidden");
        } else {
            this.loadingOverlayTarget.classList.add("hidden");
            this.mainContentTarget.classList.remove("hidden");
        }

        // Update user prompt from server only when not focused and not overwriting user edits
        if (
            data.userPrompt &&
            document.activeElement !== this.userPromptTarget &&
            (this.lastAppliedUserPrompt === null || this.userPromptTarget.value === this.lastAppliedUserPrompt)
        ) {
            this.userPromptTarget.value = data.userPrompt;
            this.lastAppliedUserPrompt = data.userPrompt;
        }

        // Update button states
        this.updateButtonStates();

        // Dispatch state changes only to cards whose image data actually changed,
        // but force-dispatch to all cards when the generating state transitions
        // (children read data-photo-builder-generating to enable/disable buttons).
        const generatingChanged = prevAnyGenerating !== this.anyGenerating;
        for (const image of images) {
            const card = this.imageCardTargets[image.position];
            if (!card) continue;
            const prev = prevImages[image.position];
            if (prev && imageDataEqual(prev, image) && !generatingChanged) continue;
            card.dispatchEvent(
                new CustomEvent("photo-builder:stateChanged", {
                    detail: image,
                    bubbles: false,
                }),
            );
        }
    }

    private updateButtonStates(): void {
        const generating = this.anyGenerating || this.isRegeneratingPrompts;

        // Disable regenerate prompts button while generating or regenerating prompts
        if (this.regeneratePromptsButtonTarget) {
            this.regeneratePromptsButtonTarget.disabled = generating;
        }

        // Disable embed button while generating
        if (this.hasEmbedButtonTarget) {
            this.embedButtonTarget.disabled = generating;
        }

        // Toggle a data attribute on the container for child controllers
        this.element.setAttribute("data-photo-builder-generating", generating ? "true" : "false");
    }

    /**
     * Handle "Regenerate image prompts" button click.
     */
    async regeneratePrompts(): Promise<void> {
        if (!this.sessionId || this.anyGenerating) return;

        this.startActivePolling();

        // Tell child cards to clear prompt textarea if not kept (shows pulsing immediately)
        for (const card of this.imageCardTargets) {
            card.dispatchEvent(new CustomEvent("photo-builder:clearPromptIfNotKept", { bubbles: false }));
        }

        this.isRegeneratingPrompts = true;
        this.updateButtonStates();

        // Collect kept image IDs from child controllers
        const keptImageIds: string[] = [];
        for (const card of this.imageCardTargets) {
            const keepCheckbox = card.querySelector(
                '[data-photo-image-target="keepCheckbox"]',
            ) as HTMLInputElement | null;
            const imageId = card.getAttribute("data-photo-image-image-id");
            if (keepCheckbox?.checked && imageId) {
                keptImageIds.push(imageId);
            }
        }

        try {
            const url = this.regeneratePromptsUrlPatternValue.replace(SESSION_ID_PLACEHOLDER, this.sessionId);
            await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    userPrompt: this.userPromptTarget.value,
                    keepImageIds: keptImageIds,
                }),
            });

            // Polling will pick up the new state; overlay is hidden in handlePollResponse
        } catch {
            this.hideRegeneratingPromptsOverlay();
        }
    }

    private hideRegeneratingPromptsOverlay(): void {
        this.isRegeneratingPrompts = false;
        if (this.hasRegeneratingPromptsOverlayTarget) {
            this.regeneratingPromptsOverlayTarget.classList.add("hidden");
        }
    }

    /**
     * Handle photo-image:promptEdited event from child.
     * Debounces so rapid keystrokes result in a single API call per image after typing stops.
     */
    handlePromptEdited(event: CustomEvent<PromptEditedDetail>): void {
        const { imageId, prompt } = event.detail as unknown as {
            imageId: string;
            prompt: string;
        };
        if (!imageId) return;

        const timeouts = this.promptDebounceTimeouts ?? {};
        if (timeouts[imageId]) {
            clearTimeout(timeouts[imageId]);
        }
        this.promptDebounceTimeouts = timeouts;
        this.promptDebounceTimeouts[imageId] = setTimeout(() => {
            delete this.promptDebounceTimeouts![imageId];
            const url = this.updatePromptUrlPatternValue.replace(IMAGE_ID_PLACEHOLDER, imageId);
            fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ prompt }),
            }).catch(() => {
                // Silently ignore
            });
        }, PROMPT_DEBOUNCE_MS);
    }

    /**
     * Handle photo-image:regenerateRequested event from child.
     */
    async handleRegenerateImage(event: CustomEvent<RegenerateRequestedDetail>): Promise<void> {
        const { imageId } = event.detail;
        if (!imageId || this.anyGenerating) return;

        this.startActivePolling();

        try {
            const url = this.regenerateImageUrlPatternValue.replace(IMAGE_ID_PLACEHOLDER, imageId);
            await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ imageSize: this.currentImageSize }),
            });
        } catch {
            // Silently ignore
        }
    }

    /**
     * Handle resolution toggle (lo-res / hi-res) button click.
     * Re-generates all images at the new resolution without reloading the page.
     */
    async switchResolution(event: Event): Promise<void> {
        if (!this.sessionId || this.anyGenerating) return;

        this.startActivePolling();

        const target = event.currentTarget as HTMLElement;
        const newSize = target.dataset.imageSize ?? this.currentImageSize;
        if (newSize === this.currentImageSize) return;

        this.currentImageSize = newSize;
        this.updateResolutionToggleUi();

        // Reset all image cards to generating placeholder immediately
        for (const card of this.imageCardTargets) {
            card.dispatchEvent(new CustomEvent("photo-builder:resetToGenerating", { bubbles: false }));
        }

        try {
            const url = this.regenerateAllImagesUrlPatternValue.replace(SESSION_ID_PLACEHOLDER, this.sessionId);
            await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ imageSize: this.currentImageSize }),
            });
            // Polling will pick up the regeneration
        } catch {
            // Silently ignore
        }
    }

    private updateResolutionToggleUi(): void {
        if (!this.hasLoresButtonTarget || !this.hasHiresButtonTarget) return;

        const activeClasses = ["bg-primary-600", "text-white", "shadow-sm"];
        const inactiveClasses = [
            "text-dark-600",
            "dark:text-dark-400",
            "hover:text-dark-900",
            "dark:hover:text-dark-200",
        ];

        const isLores = this.currentImageSize === "1K";
        if (isLores) {
            this.loresButtonTarget.classList.add(...activeClasses);
            this.loresButtonTarget.classList.remove(...inactiveClasses);
            this.hiresButtonTarget.classList.remove(...activeClasses);
            this.hiresButtonTarget.classList.add(...inactiveClasses);
        } else {
            this.hiresButtonTarget.classList.add(...activeClasses);
            this.hiresButtonTarget.classList.remove(...inactiveClasses);
            this.loresButtonTarget.classList.remove(...activeClasses);
            this.loresButtonTarget.classList.add(...inactiveClasses);
        }
    }

    /**
     * Upload a single image to the media store.
     * Returns the actual S3 filename (hash-prefixed) on success, null on failure.
     */
    private async uploadImageToMediaStore(imageId: string): Promise<string | null> {
        try {
            const url = this.uploadToMediaStoreUrlPatternValue.replace(IMAGE_ID_PLACEHOLDER, imageId);
            const response = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
            if (!response.ok) {
                return null;
            }
            const data = (await response.json()) as { uploadedFileName?: string };
            return data.uploadedFileName ?? null;
        } catch {
            return null;
        }
    }

    /**
     * Handle photo-image:uploadRequested event from child.
     */
    async handleUploadToMediaStore(event: CustomEvent<UploadRequestedDetail>): Promise<void> {
        const { imageId } = event.detail;
        if (!imageId) return;

        const cardFromEvent =
            event.target instanceof HTMLElement
                ? (event.target.closest("[data-photo-builder-target='imageCard']") as HTMLElement | null)
                : null;
        const uploadedFileName = await this.uploadImageToMediaStore(imageId);
        const card =
            cardFromEvent ??
            this.imageCardTargets.find((el) => el.getAttribute("data-photo-image-image-id") === imageId);
        if (uploadedFileName !== null) {
            if (card) {
                card.dispatchEvent(
                    new CustomEvent("photo-builder:uploadComplete", {
                        detail: { imageId },
                        bubbles: true,
                    }),
                );
            }
        } else {
            if (card) {
                card.dispatchEvent(
                    new CustomEvent("photo-builder:uploadFailed", {
                        detail: { imageId },
                        bubbles: true,
                    }),
                );
            }
        }
    }

    /**
     * Handle remote-asset-browser:uploadComplete event (e.g. upload via sidebar dropzone).
     */
    handleMediaStoreUploadComplete(): void {}

    /**
     * Navigate back to editor with pre-filled embed message.
     * Uploads any completed images that are not yet on the media store first,
     * then waits for them to appear in a remote asset manifest before redirecting.
     */
    async embedIntoPage(): Promise<void> {
        const completedImages = this.lastImages.filter((img) => img.suggestedFileName && img.status === "completed");

        if (completedImages.length === 0) {
            return;
        }

        const imagesToUpload = completedImages.filter((img) => img.uploadedToMediaStore !== true);

        const uploadedFileNamesByImageId: Record<string, string> = {};

        if (imagesToUpload.length > 0) {
            if (this.hasUploadingImagesOverlayTarget) {
                this.uploadingImagesOverlayTarget.classList.remove("hidden");
            }
            if (this.hasEmbedButtonTarget) {
                this.embedButtonTarget.disabled = true;
            }

            const results = await Promise.all(
                imagesToUpload.map(async (img) => {
                    const fn = await this.uploadImageToMediaStore(img.id);
                    if (fn !== null) {
                        uploadedFileNamesByImageId[img.id] = fn;
                    }
                    return fn !== null;
                }),
            );

            if (this.hasUploadingImagesOverlayTarget) {
                this.uploadingImagesOverlayTarget.classList.add("hidden");
            }
            if (this.hasEmbedButtonTarget) {
                this.embedButtonTarget.disabled = this.anyGenerating;
            }

            const allSucceeded = results.every(Boolean);
            if (!allSucceeded) {
                return;
            }
        }

        const allUploadedFileNames = completedImages
            .map((img) => uploadedFileNamesByImageId[img.id] ?? img.uploadedFileName ?? "")
            .filter(Boolean);

        // Wait for all uploaded files to appear in the remote manifest
        if (allUploadedFileNames.length > 0 && this.checkManifestAvailabilityUrlPatternValue) {
            await this.waitForManifestAvailability(allUploadedFileNames);
        }

        const fileNames = completedImages
            .map((img) => uploadedFileNamesByImageId[img.id] ?? img.uploadedFileName ?? img.suggestedFileName ?? "")
            .filter(Boolean)
            .join(", ");
        const message = this.embedPrefillMessageValue
            .replace("%fileNames%", fileNames)
            .replace("%pagePath%", this.pagePathValue);
        const url = `${this.editorUrlValue}?prefill=${encodeURIComponent(message)}`;
        window.location.href = url;
    }

    /**
     * Poll the check-manifest-availability endpoint until all filenames are found
     * or a timeout (~90 seconds) is reached.
     */
    private async waitForManifestAvailability(fileNames: string[]): Promise<boolean> {
        if (this.hasWaitingForManifestOverlayTarget) {
            this.waitingForManifestOverlayTarget.classList.remove("hidden");
        }
        if (this.hasEmbedButtonTarget) {
            this.embedButtonTarget.disabled = true;
        }

        const pollIntervalMs = 3000;
        const maxAttempts = 30; // ~90 seconds
        const url = this.checkManifestAvailabilityUrlPatternValue;

        let allAvailable = false;

        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            try {
                const response = await fetch(url, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": this.csrfTokenValue,
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    body: JSON.stringify({ fileNames }),
                });

                if (response.ok) {
                    const data = (await response.json()) as { allAvailable?: boolean };
                    if (data.allAvailable === true) {
                        allAvailable = true;
                        break;
                    }
                }
            } catch {
                // Ignore individual poll errors, keep trying
            }

            await new Promise((resolve) => setTimeout(resolve, pollIntervalMs));
        }

        if (this.hasWaitingForManifestOverlayTarget) {
            this.waitingForManifestOverlayTarget.classList.add("hidden");
        }
        if (this.hasEmbedButtonTarget) {
            this.embedButtonTarget.disabled = this.anyGenerating;
        }

        return allAvailable;
    }
}
