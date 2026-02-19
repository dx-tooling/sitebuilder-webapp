import { Controller } from "@hotwired/stimulus";

interface TranslationsData {
    editing: string;
    saveChanges: string;
    close: string;
    saving: string;
    building: string;
    saveSuccess: string;
    saveError: string;
    buildError: string;
    loadError: string;
}

interface SaveResponse {
    buildId?: string;
    error?: string;
}

interface BuildStatusResponse {
    status: string;
    error: string | null;
}

/**
 * Stimulus controller for the HTML editor.
 * Allows users to edit HTML content of dist files directly.
 *
 * After saving, an async build is dispatched via Symfony Messenger.
 * The controller polls the build status endpoint until completed or failed.
 */
export default class extends Controller {
    static values = {
        loadUrl: String,
        saveUrl: String,
        buildStatusUrlTemplate: String,
        workspaceId: String,
        translations: Object,
    };

    static targets = [
        "container",
        "textarea",
        "pageNameDisplay",
        "saveButton",
        "closeButton",
        "loading",
        "error",
        "success",
        "chatArea",
    ];

    declare readonly loadUrlValue: string;
    declare readonly saveUrlValue: string;
    declare readonly buildStatusUrlTemplateValue: string;
    declare readonly workspaceIdValue: string;
    declare readonly translationsValue: TranslationsData;

    declare readonly hasContainerTarget: boolean;
    declare readonly containerTarget: HTMLElement;
    declare readonly hasTextareaTarget: boolean;
    declare readonly textareaTarget: HTMLTextAreaElement;
    declare readonly hasPageNameDisplayTarget: boolean;
    declare readonly pageNameDisplayTarget: HTMLElement;
    declare readonly hasSaveButtonTarget: boolean;
    declare readonly saveButtonTarget: HTMLButtonElement;
    declare readonly hasCloseButtonTarget: boolean;
    declare readonly closeButtonTarget: HTMLButtonElement;
    declare readonly hasLoadingTarget: boolean;
    declare readonly loadingTarget: HTMLElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;
    declare readonly hasSuccessTarget: boolean;
    declare readonly successTarget: HTMLElement;
    declare readonly hasChatAreaTarget: boolean;
    declare readonly chatAreaTarget: HTMLElement;

    private currentPath: string = "";
    private csrfToken: string = "";
    private buildPollTimeoutId: ReturnType<typeof setTimeout> | null = null;

    connect(): void {
        const csrfInput = document.querySelector<HTMLInputElement>('input[name="_html_editor_csrf_token"]');
        if (csrfInput) {
            this.csrfToken = csrfInput.value;
        }
    }

    disconnect(): void {
        this.stopBuildPolling();
    }

    /**
     * Open the HTML editor for a specific file.
     * Called via custom event from dist_files_controller.
     */
    async openEditor(event: CustomEvent<{ path: string }>): Promise<void> {
        const path = event.detail?.path;
        if (!path) {
            return;
        }

        this.currentPath = path;
        this.showLoading();
        this.hideError();
        this.hideSuccess();

        if (this.hasChatAreaTarget) {
            this.chatAreaTarget.classList.remove("grid-rows-[1fr]");
            this.chatAreaTarget.classList.add("grid-rows-[0fr]");
        }
        if (this.hasContainerTarget) {
            this.containerTarget.classList.remove("grid-rows-[0fr]");
            this.containerTarget.classList.add("grid-rows-[1fr]");
        }

        if (this.hasPageNameDisplayTarget) {
            const sourcePath = path.startsWith("dist/") ? "src/" + path.substring(5) : path;
            this.pageNameDisplayTarget.textContent = `${sourcePath}`;
        }

        try {
            const response = await fetch(`${this.loadUrlValue}?path=${encodeURIComponent(path)}`, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            const data = (await response.json()) as { content?: string; error?: string };

            if (!response.ok || data.error) {
                this.showError(data.error || this.translationsValue.loadError);
                this.hideLoading();

                return;
            }

            if (this.hasTextareaTarget && data.content !== undefined) {
                this.textareaTarget.value = data.content;
            }

            this.hideLoading();
        } catch (err) {
            const msg = err instanceof Error ? err.message : this.translationsValue.loadError;
            this.showError(msg);
            this.hideLoading();
        }
    }

    /**
     * Save the HTML changes and start polling for build completion.
     */
    async saveChanges(): Promise<void> {
        if (!this.currentPath || !this.hasTextareaTarget) {
            return;
        }

        this.hideError();
        this.hideSuccess();
        this.setBusy(true, this.translationsValue.saving);

        const formData = new FormData();
        formData.append("path", this.currentPath);
        formData.append("content", this.textareaTarget.value);
        formData.append("_csrf_token", this.csrfToken);

        try {
            const response = await fetch(this.saveUrlValue, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData,
            });

            const data = (await response.json()) as SaveResponse;

            if (!response.ok || data.error) {
                this.showError(data.error || this.translationsValue.saveError);
                this.setBusy(false);

                return;
            }

            if (data.buildId) {
                this.setBusy(true, this.translationsValue.building);
                this.pollBuildStatus(data.buildId);
            } else {
                this.showSuccess(this.translationsValue.saveSuccess);
                this.setBusy(false);
            }
        } catch (err) {
            const msg = err instanceof Error ? err.message : this.translationsValue.saveError;
            this.showError(msg);
            this.setBusy(false);
        }
    }

    closeEditor(): void {
        this.currentPath = "";
        this.hideError();
        this.hideSuccess();
        this.stopBuildPolling();

        if (this.hasTextareaTarget) {
            this.textareaTarget.value = "";
        }

        if (this.hasContainerTarget) {
            this.containerTarget.classList.remove("grid-rows-[1fr]");
            this.containerTarget.classList.add("grid-rows-[0fr]");
        }
        if (this.hasChatAreaTarget) {
            this.chatAreaTarget.classList.remove("grid-rows-[0fr]");
            this.chatAreaTarget.classList.add("grid-rows-[1fr]");
        }
    }

    private pollBuildStatus(buildId: string): void {
        this.stopBuildPolling();

        const url = this.buildStatusUrlTemplateValue.replace("00000000-0000-0000-0000-000000000000", buildId);

        const doPoll = async (): Promise<void> => {
            try {
                const response = await fetch(url, {
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });

                if (!response.ok) {
                    this.showError(this.translationsValue.buildError);
                    this.setBusy(false);

                    return;
                }

                const data = (await response.json()) as BuildStatusResponse;

                if (data.status === "completed") {
                    this.showSuccess(this.translationsValue.saveSuccess);
                    this.setBusy(false);

                    return;
                }

                if (data.status === "failed") {
                    this.showError(data.error || this.translationsValue.buildError);
                    this.setBusy(false);

                    return;
                }

                // Still pending or running -- schedule next poll
                this.buildPollTimeoutId = setTimeout(() => void doPoll(), 1000);
            } catch {
                this.showError(this.translationsValue.buildError);
                this.setBusy(false);
            }
        };

        void doPoll();
    }

    private stopBuildPolling(): void {
        if (this.buildPollTimeoutId !== null) {
            clearTimeout(this.buildPollTimeoutId);
            this.buildPollTimeoutId = null;
        }
    }

    private showLoading(): void {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove("hidden");
        }
        if (this.hasTextareaTarget) {
            this.textareaTarget.classList.add("hidden");
        }
    }

    private hideLoading(): void {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.add("hidden");
        }
        if (this.hasTextareaTarget) {
            this.textareaTarget.classList.remove("hidden");
        }
    }

    private showError(message: string): void {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message;
            this.errorTarget.classList.remove("hidden");
        }
    }

    private hideError(): void {
        if (this.hasErrorTarget) {
            this.errorTarget.classList.add("hidden");
        }
    }

    private showSuccess(message: string): void {
        if (this.hasSuccessTarget) {
            this.successTarget.textContent = message;
            this.successTarget.classList.remove("hidden");
        }
    }

    private hideSuccess(): void {
        if (this.hasSuccessTarget) {
            this.successTarget.classList.add("hidden");
        }
    }

    private setBusy(busy: boolean, buttonText?: string): void {
        if (this.hasSaveButtonTarget) {
            this.saveButtonTarget.disabled = busy;
            this.saveButtonTarget.textContent = busy
                ? (buttonText ?? this.translationsValue.saving)
                : this.translationsValue.saveChanges;
        }
        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.disabled = busy;
        }
    }
}
