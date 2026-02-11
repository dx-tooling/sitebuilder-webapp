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
        updatePromptUrlPattern: String,
        uploadToMediaStoreUrlPattern: String,
        csrfToken: String,
        workspaceId: String,
        pagePath: String,
        conversationId: String,
        imageCount: Number,
        defaultUserPrompt: String,
        editorUrl: String,
        hasRemoteAssets: Boolean,
    };

    static targets = [
        "loadingOverlay",
        "mainContent",
        "userPrompt",
        "regeneratePromptsButton",
        "embedButton",
        "imageCard",
        "uploadFinishedBanner",
        "regeneratingPromptsOverlay",
        "uploadingImagesOverlay",
    ];

    declare readonly createSessionUrlValue: string;
    declare readonly pollUrlPatternValue: string;
    declare readonly regeneratePromptsUrlPatternValue: string;
    declare readonly regenerateImageUrlPatternValue: string;
    declare readonly updatePromptUrlPatternValue: string;
    declare readonly uploadToMediaStoreUrlPatternValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly workspaceIdValue: string;
    declare readonly pagePathValue: string;
    declare readonly conversationIdValue: string;
    declare readonly imageCountValue: number;
    declare readonly defaultUserPromptValue: string;
    declare readonly editorUrlValue: string;
    declare readonly hasRemoteAssetsValue: boolean;

    declare readonly loadingOverlayTarget: HTMLElement;
    declare readonly mainContentTarget: HTMLElement;
    declare readonly userPromptTarget: HTMLTextAreaElement;
    declare readonly regeneratePromptsButtonTarget: HTMLButtonElement;
    declare readonly hasEmbedButtonTarget: boolean;
    declare readonly embedButtonTarget: HTMLButtonElement;
    declare readonly imageCardTargets: HTMLElement[];
    declare readonly hasUploadFinishedBannerTarget: boolean;
    declare readonly uploadFinishedBannerTarget: HTMLElement;
    declare readonly hasRegeneratingPromptsOverlayTarget: boolean;
    declare readonly regeneratingPromptsOverlayTarget: HTMLElement;
    declare readonly hasUploadingImagesOverlayTarget: boolean;
    declare readonly uploadingImagesOverlayTarget: HTMLElement;

    private sessionId: string | null = null;
    private isRegeneratingPrompts = false;
    private pollingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private isActive = false;
    private anyGenerating = false;
    private lastImages: ImageData[] = [];

    connect(): void {
        this.isActive = true;
        this.createSession();
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
        if (this.isActive && this.sessionId) {
            this.pollingTimeoutId = setTimeout(() => this.poll(), 1000);
        }
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
        const images = data.images || [];
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

        // Update user prompt if not focused
        if (data.userPrompt && document.activeElement !== this.userPromptTarget) {
            this.userPromptTarget.value = data.userPrompt;
        }

        // Update button states
        this.updateButtonStates();

        // Dispatch state changes to each image card
        for (const image of images) {
            const card = this.imageCardTargets[image.position];
            if (card) {
                card.dispatchEvent(
                    new CustomEvent("photo-builder:stateChanged", {
                        detail: image,
                        bubbles: false,
                    }),
                );
            }
        }
    }

    private updateButtonStates(): void {
        // Disable regenerate prompts button while generating
        if (this.regeneratePromptsButtonTarget) {
            this.regeneratePromptsButtonTarget.disabled = this.anyGenerating;
        }

        // Disable embed button while generating
        if (this.hasEmbedButtonTarget) {
            this.embedButtonTarget.disabled = this.anyGenerating;
        }

        // Toggle a data attribute on the container for child controllers
        this.element.setAttribute("data-photo-builder-generating", this.anyGenerating ? "true" : "false");
    }

    /**
     * Handle "Regenerate image prompts" button click.
     */
    async regeneratePrompts(): Promise<void> {
        if (!this.sessionId || this.anyGenerating) return;

        // Tell child cards to clear prompt textarea if not kept (native event so children can listen)
        this.element.dispatchEvent(new CustomEvent("photo-builder:clearPromptIfNotKept", { bubbles: true }));

        // Show regenerating overlay and spinner
        this.isRegeneratingPrompts = true;
        if (this.hasRegeneratingPromptsOverlayTarget) {
            this.regeneratingPromptsOverlayTarget.classList.remove("hidden");
        }

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
     */
    handlePromptEdited(event: CustomEvent<PromptEditedDetail>): void {
        const { imageId } = event.detail as unknown as {
            imageId: string;
            prompt: string;
        };
        if (!imageId) return;

        // Persist prompt update to backend
        const url = this.updatePromptUrlPatternValue.replace(IMAGE_ID_PLACEHOLDER, imageId);
        fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": this.csrfTokenValue,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({
                prompt: (event.detail as unknown as { prompt: string }).prompt,
            }),
        }).catch(() => {
            // Silently ignore
        });
    }

    /**
     * Handle photo-image:regenerateRequested event from child.
     */
    async handleRegenerateImage(event: CustomEvent<RegenerateRequestedDetail>): Promise<void> {
        const { imageId } = event.detail;
        if (!imageId || this.anyGenerating) return;

        try {
            const url = this.regenerateImageUrlPatternValue.replace(IMAGE_ID_PLACEHOLDER, imageId);
            await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": this.csrfTokenValue,
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
        } catch {
            // Silently ignore
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

        const uploadedFileName = await this.uploadImageToMediaStore(imageId);
        if (uploadedFileName !== null) {
            this.showUploadFinishedBanner();
        }
    }

    /**
     * Handle remote-asset-browser:uploadComplete event (e.g. upload via sidebar dropzone).
     */
    handleMediaStoreUploadComplete(): void {
        this.showUploadFinishedBanner();
    }

    /**
     * Show the "Upload has been finished" banner and auto-hide after 5s.
     */
    private showUploadFinishedBanner(): void {
        if (!this.hasUploadFinishedBannerTarget) {
            return;
        }
        const banner = this.uploadFinishedBannerTarget;
        banner.classList.remove("hidden");
        const hideAfterMs = 5000;
        setTimeout(() => {
            banner.classList.add("hidden");
        }, hideAfterMs);
    }

    /**
     * Navigate back to editor with pre-filled embed message.
     * Uploads any completed images that are not yet on the media store first.
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

        const fileNames = completedImages
            .map((img) => uploadedFileNamesByImageId[img.id] ?? img.uploadedFileName ?? img.suggestedFileName ?? "")
            .filter(Boolean)
            .join(", ");
        const message = `Embed images ${fileNames} into page ${this.pagePathValue}`;
        const url = `${this.editorUrlValue}?prefill=${encodeURIComponent(message)}`;
        window.location.href = url;
    }
}
