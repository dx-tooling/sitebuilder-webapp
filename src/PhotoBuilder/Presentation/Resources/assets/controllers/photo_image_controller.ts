import { Controller } from "@hotwired/stimulus";

interface ImageStateDetail {
    id: string;
    position: number;
    prompt: string | null;
    suggestedFileName: string | null;
    status: string;
    imageUrl: string | null;
    errorMessage: string | null;
    uploadedToMediaStore?: boolean;
}

/**
 * Per-image card controller for the PhotoBuilder.
 *
 * Manages individual image display, prompt editing,
 * and dispatches events to the parent photo-builder controller.
 */
export default class extends Controller {
    static values = {
        position: Number,
        hasMediaStore: Boolean,
        generatingPromptText: { type: String, default: "Generating..." },
    };

    static targets = [
        "image",
        "placeholder",
        "promptTextarea",
        "keepCheckbox",
        "regenerateButton",
        "uploadButton",
        "uploadButtonDefault",
        "uploadButtonUploading",
        "uploadButtonSuccess",
        "statusBadge",
    ];

    declare readonly positionValue: number;
    declare readonly hasMediaStoreValue: boolean;
    declare readonly generatingPromptTextValue: string;

    declare readonly imageTarget: HTMLImageElement;
    declare readonly placeholderTarget: HTMLElement;
    declare readonly promptTextareaTarget: HTMLTextAreaElement;
    declare readonly keepCheckboxTarget: HTMLInputElement;
    declare readonly regenerateButtonTarget: HTMLButtonElement;
    declare readonly hasUploadButtonTarget: boolean;
    declare readonly uploadButtonTarget: HTMLButtonElement;
    declare readonly hasUploadButtonDefaultTarget: boolean;
    declare readonly uploadButtonDefaultTarget: HTMLElement;
    declare readonly hasUploadButtonUploadingTarget: boolean;
    declare readonly uploadButtonUploadingTarget: HTMLElement;
    declare readonly hasUploadButtonSuccessTarget: boolean;
    declare readonly uploadButtonSuccessTarget: HTMLElement;
    declare readonly statusBadgeTarget: HTMLElement;

    private imageId: string | null = null;
    private currentStatus = "pending";
    private suggestedFileName: string | null = null;
    private promptAwaitingRegenerate = false;
    /** The prompt value before regeneration was requested; used to detect when a genuinely new prompt arrives. */
    private promptBeforeRegenerate: string | null = null;
    private uploadInProgress = false;
    private uploadJustSucceeded = false;
    private uploadedToMediaStore = false;
    /** Last image URL we set on the img element (without query string), to avoid re-requesting the same image. */
    private lastSetImageUrl: string | null = null;

    /**
     * Called by parent photo-builder controller via event dispatch.
     */
    updateFromState(event: CustomEvent<ImageStateDetail>): void {
        const data = event.detail;

        this.imageId = data.id;
        this.currentStatus = data.status;
        this.suggestedFileName = data.suggestedFileName;
        this.uploadedToMediaStore = data.uploadedToMediaStore ?? false;

        // Store imageId on the element for parent to read
        this.element.setAttribute("data-photo-image-image-id", data.id);

        // Update prompt textarea
        if (document.activeElement !== this.promptTextareaTarget) {
            if (this.promptAwaitingRegenerate) {
                // Apply prompt once it differs from the pre-regeneration value
                // (regardless of status — the image may already be "completed" if generation was fast).
                if (data.prompt !== null && data.prompt !== "" && data.prompt !== this.promptBeforeRegenerate) {
                    this.promptTextareaTarget.value = data.prompt;
                    this.promptTextareaTarget.classList.remove("animate-pulse");
                    this.promptAwaitingRegenerate = false;
                    this.promptBeforeRegenerate = null;
                }
            } else if (data.prompt !== null) {
                this.promptTextareaTarget.value = data.prompt;
            }
        }

        // Update image visibility
        this.updateImageDisplay(data);

        // Update status badge
        this.updateStatusBadge(data);

        // Update button states based on parent's generating state
        this.updateButtonStates();

        if (this.hasUploadButtonDefaultTarget) {
            this.updateUploadButtonState();
        }
    }

    private updateImageDisplay(data: ImageStateDetail): void {
        if (data.status === "completed" && data.imageUrl) {
            const baseUrl = data.imageUrl.split("?")[0];
            if (this.lastSetImageUrl !== baseUrl) {
                this.imageTarget.src = baseUrl + "?v=" + Date.now();
                this.lastSetImageUrl = baseUrl;
            }
            this.imageTarget.classList.remove("hidden");
            this.placeholderTarget.classList.add("hidden");
        } else if (data.status === "generating" || data.status === "pending") {
            this.lastSetImageUrl = null;
            this.imageTarget.classList.add("hidden");
            this.placeholderTarget.classList.remove("hidden");
        } else if (data.status === "failed") {
            this.lastSetImageUrl = null;
            this.imageTarget.classList.add("hidden");
            this.placeholderTarget.classList.remove("hidden");
            // Show error in placeholder
            this.placeholderTarget.innerHTML = `
                <div class="absolute inset-0 flex flex-col items-center justify-center space-y-2 text-red-500">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                    </svg>
                    <span class="text-xs text-center px-2">${data.errorMessage || "Generation failed"}</span>
                </div>
            `;
        }
    }

    private updateStatusBadge(data: ImageStateDetail): void {
        const badge = this.statusBadgeTarget;

        if (data.status === "completed") {
            badge.classList.remove("hidden");
            badge.className =
                "absolute top-2 right-2 px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300";
            badge.textContent = "Done";
        } else if (data.status === "generating") {
            badge.classList.remove("hidden");
            badge.className =
                "absolute top-2 right-2 px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-300 animate-pulse";
            badge.textContent = "Generating...";
        } else if (data.status === "failed") {
            badge.classList.remove("hidden");
            badge.className =
                "absolute top-2 right-2 px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300";
            badge.textContent = "Failed";
        } else {
            badge.classList.add("hidden");
        }
    }

    private updateButtonStates(): void {
        const parentGenerating =
            this.element.closest("[data-photo-builder-generating]")?.getAttribute("data-photo-builder-generating") ===
            "true";

        this.regenerateButtonTarget.disabled = parentGenerating || this.currentStatus === "generating";

        if (this.hasUploadButtonTarget) {
            this.uploadButtonTarget.disabled =
                parentGenerating ||
                this.currentStatus !== "completed" ||
                this.uploadInProgress ||
                this.uploadedToMediaStore;
        }
    }

    private updateUploadButtonState(): void {
        if (
            !this.hasUploadButtonDefaultTarget ||
            !this.hasUploadButtonUploadingTarget ||
            !this.hasUploadButtonSuccessTarget
        ) {
            return;
        }
        const showDefault = !this.uploadInProgress && !this.uploadJustSucceeded && !this.uploadedToMediaStore;
        const showUploading = this.uploadInProgress;
        const showSuccess = this.uploadJustSucceeded || this.uploadedToMediaStore;

        this.uploadButtonDefaultTarget.classList.toggle("hidden", !showDefault);
        this.uploadButtonUploadingTarget.classList.toggle("hidden", !showUploading);
        this.uploadButtonSuccessTarget.classList.toggle("hidden", !showSuccess);
    }

    /**
     * Handle photo-builder:uploadComplete event from parent.
     */
    onUploadComplete(event: CustomEvent<{ imageId: string }>): void {
        if (event.detail.imageId !== this.imageId) {
            return;
        }
        this.uploadInProgress = false;
        this.uploadJustSucceeded = true;
        this.updateButtonStates();
        this.updateUploadButtonState();
        const hideSuccessAfterMs = 3000;
        setTimeout(() => {
            this.uploadJustSucceeded = false;
            this.updateUploadButtonState();
        }, hideSuccessAfterMs);
    }

    /**
     * Handle photo-builder:uploadFailed event from parent.
     */
    onUploadFailed(event: CustomEvent<{ imageId: string }>): void {
        if (event.detail.imageId !== this.imageId) {
            return;
        }
        this.uploadInProgress = false;
        this.uploadJustSucceeded = false;
        this.updateButtonStates();
        this.updateUploadButtonState();
    }

    /**
     * Called by parent when "Regenerate image prompts" is clicked.
     * Replaces prompt with "Generating..." if not kept, and shows pulsing.
     */
    clearPromptIfNotKept(): void {
        if (!this.keepCheckboxTarget.checked) {
            this.promptBeforeRegenerate = this.promptTextareaTarget.value;
            this.promptTextareaTarget.value = this.generatingPromptTextValue;
            this.promptTextareaTarget.classList.add("animate-pulse");
            this.promptAwaitingRegenerate = true;
        }
    }

    /**
     * Handle prompt textarea input — auto-check "Keep prompt" and dispatch event.
     */
    onPromptInput(): void {
        this.keepCheckboxTarget.checked = true;

        this.dispatch("promptEdited", {
            detail: {
                position: this.positionValue,
                imageId: this.imageId,
                prompt: this.promptTextareaTarget.value,
            },
        });
    }

    /**
     * Handle "Regenerate image" button click.
     */
    requestRegenerate(): void {
        if (!this.imageId) return;

        this.dispatch("regenerateRequested", {
            detail: {
                position: this.positionValue,
                imageId: this.imageId,
                prompt: this.promptTextareaTarget.value,
            },
        });
    }

    /**
     * Handle "Upload to media store" button click.
     */
    requestUpload(): void {
        if (!this.imageId) return;

        this.uploadInProgress = true;
        this.updateButtonStates();
        this.updateUploadButtonState();

        this.dispatch("uploadRequested", {
            detail: {
                position: this.positionValue,
                imageId: this.imageId,
                suggestedFileName: this.suggestedFileName ?? "",
            },
        });
    }
}
