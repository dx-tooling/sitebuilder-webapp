import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for LLM API key verification.
 * Handles blur events on the API key input to verify the key,
 * shows loading/success/error states, and supports reusing existing keys.
 */
export default class extends Controller {
    static values = {
        verifyUrl: String,
        csrfToken: String,
    };

    static targets = ["input", "status", "spinner", "success", "error"];

    declare readonly verifyUrlValue: string;
    declare readonly csrfTokenValue: string;

    declare readonly hasInputTarget: boolean;
    declare readonly inputTarget: HTMLInputElement;
    declare readonly hasSpinnerTarget: boolean;
    declare readonly spinnerTarget: HTMLElement;
    declare readonly hasSuccessTarget: boolean;
    declare readonly successTarget: HTMLElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;

    private isVerifying: boolean = false;

    /**
     * Triggered when the API key input loses focus.
     */
    async verify(): Promise<void> {
        if (!this.hasInputTarget) {
            return;
        }

        const apiKey = this.inputTarget.value.trim();

        // Don't verify if empty
        if (apiKey === "") {
            return;
        }

        // Don't start a new verification if one is in progress
        if (this.isVerifying) {
            return;
        }

        await this.performVerification(apiKey);
    }

    /**
     * Reuses an existing API key by copying it to the input and triggering verification.
     */
    async reuseKey(event: Event): Promise<void> {
        const button = event.currentTarget as HTMLElement;
        const apiKey = button.dataset.llmKeyVerificationKeyParam;

        if (!apiKey || !this.hasInputTarget) {
            return;
        }

        this.inputTarget.value = apiKey;
        await this.performVerification(apiKey);
    }

    /**
     * Performs the actual API key verification.
     */
    private async performVerification(apiKey: string): Promise<void> {
        this.isVerifying = true;

        // Get the selected provider: look in the closest fieldset/form ancestor first
        // (covers the PhotoBuilder dedicated settings panel where radios are siblings),
        // then fall back to the content editing provider radio.
        const scope = this.element.closest("fieldset") ?? this.element.closest("form") ?? document;
        const providerInput = (scope.querySelector('input[name$="_llm_model_provider"]:checked') ??
            document.querySelector(
                'input[name="content_editing_llm_model_provider"]:checked',
            )) as HTMLInputElement | null;
        const provider = providerInput?.value || "openai";

        // Show spinner, hide others
        this.showStatus("spinner");

        // Disable input during verification
        if (this.hasInputTarget) {
            this.inputTarget.disabled = true;
        }

        try {
            const formData = new FormData();
            formData.append("provider", provider);
            formData.append("api_key", apiKey);
            formData.append("_csrf_token", this.csrfTokenValue);

            const response = await fetch(this.verifyUrlValue, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: formData,
            });

            const data = (await response.json()) as { success: boolean };

            if (data.success) {
                this.showStatus("success");
            } else {
                this.showStatus("error");
            }
        } catch {
            this.showStatus("error");
        } finally {
            this.isVerifying = false;

            // Re-enable input
            if (this.hasInputTarget) {
                this.inputTarget.disabled = false;
            }
        }
    }

    /**
     * Shows only the specified status element, hiding the others.
     */
    private showStatus(which: "spinner" | "success" | "error" | "none"): void {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle("hidden", which !== "spinner");
        }
        if (this.hasSuccessTarget) {
            this.successTarget.classList.toggle("hidden", which !== "success");
        }
        if (this.hasErrorTarget) {
            this.errorTarget.classList.toggle("hidden", which !== "error");
        }
    }
}
