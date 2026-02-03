import { Controller } from "@hotwired/stimulus";

interface TranslationsData {
    editing: string;
    saveChanges: string;
    close: string;
    saving: string;
    saveSuccess: string;
    saveError: string;
    loadError: string;
}

/**
 * Stimulus controller for the HTML editor.
 * Allows users to edit HTML content of dist files directly.
 */
export default class extends Controller {
    static values = {
        loadUrl: String,
        saveUrl: String,
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
        "contentEditorArea",
    ];

    declare readonly loadUrlValue: string;
    declare readonly saveUrlValue: string;
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
    declare readonly hasContentEditorAreaTarget: boolean;
    declare readonly contentEditorAreaTarget: HTMLElement;

    private currentPath: string = "";
    private csrfToken: string = "";

    connect(): void {
        // Get CSRF token from the page
        const csrfInput = document.querySelector<HTMLInputElement>('input[name="_html_editor_csrf_token"]');
        if (csrfInput) {
            this.csrfToken = csrfInput.value;
        }
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

        // Show editor container and hide content editor area
        if (this.hasContainerTarget) {
            this.containerTarget.classList.remove("hidden");
        }
        if (this.hasContentEditorAreaTarget) {
            this.contentEditorAreaTarget.classList.add("hidden");
        }

        // Update page name display
        if (this.hasPageNameDisplayTarget) {
            this.pageNameDisplayTarget.textContent = path;
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
     * Save the HTML changes.
     */
    async saveChanges(): Promise<void> {
        if (!this.currentPath || !this.hasTextareaTarget) {
            return;
        }

        this.hideError();
        this.hideSuccess();
        this.setSaving(true);

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

            const data = (await response.json()) as { success?: boolean; error?: string };

            if (!response.ok || data.error) {
                this.showError(data.error || this.translationsValue.saveError);
                this.setSaving(false);

                return;
            }

            this.showSuccess(this.translationsValue.saveSuccess);
            this.setSaving(false);
        } catch (err) {
            const msg = err instanceof Error ? err.message : this.translationsValue.saveError;
            this.showError(msg);
            this.setSaving(false);
        }
    }

    /**
     * Close the editor without saving.
     */
    closeEditor(): void {
        this.currentPath = "";
        this.hideError();
        this.hideSuccess();

        if (this.hasTextareaTarget) {
            this.textareaTarget.value = "";
        }

        // Hide editor container and show content editor area
        if (this.hasContainerTarget) {
            this.containerTarget.classList.add("hidden");
        }
        if (this.hasContentEditorAreaTarget) {
            this.contentEditorAreaTarget.classList.remove("hidden");
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

    private setSaving(saving: boolean): void {
        if (this.hasSaveButtonTarget) {
            this.saveButtonTarget.disabled = saving;
            this.saveButtonTarget.textContent = saving
                ? this.translationsValue.saving
                : this.translationsValue.saveChanges;
        }
        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.disabled = saving;
        }
    }
}
