import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for content assets manifest URLs field.
 * Hard check: value must be empty or a list of valid http(s) URLs (one per line); blocks submit if invalid.
 * Soft check on blur: fetches each URL via backend and shows success/warning if manifest format is valid.
 */
export default class extends Controller {
    static values = {
        verifyUrl: String,
        csrfToken: String,
        syntaxErrorLabel: String,
    };

    static targets = ["input", "status", "syntaxError", "syntaxErrorText", "spinner", "success", "warning"];

    declare readonly verifyUrlValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly syntaxErrorLabelValue: string;

    declare readonly hasInputTarget: boolean;
    declare readonly inputTarget: HTMLTextAreaElement;
    declare readonly hasStatusTarget: boolean;
    declare readonly statusTarget: HTMLElement;
    declare readonly hasSyntaxErrorTarget: boolean;
    declare readonly syntaxErrorTarget: HTMLElement;
    declare readonly hasSyntaxErrorTextTarget: boolean;
    declare readonly syntaxErrorTextTarget: HTMLElement;
    declare readonly hasSpinnerTarget: boolean;
    declare readonly spinnerTarget: HTMLElement;
    declare readonly hasSuccessTarget: boolean;
    declare readonly successTarget: HTMLElement;
    declare readonly hasWarningTarget: boolean;
    declare readonly warningTarget: HTMLElement;

    private isVerifying: boolean = false;

    /**
     * Parse value into lines and validate each is a valid http(s) URL.
     * Returns true if empty or all lines are valid URLs.
     */
    checkSyntax(): void {
        const valid = this.isSyntaxValid();
        this.showSyntaxError(!valid);
        this.hideSoftStatus();
    }

    /**
     * On blur: if non-empty and syntax valid, verify each URL with backend (soft check).
     */
    async verify(): Promise<void> {
        if (!this.hasInputTarget) {
            return;
        }

        const value = this.inputTarget.value.trim();
        if (value === "") {
            this.hideSoftStatus();
            return;
        }

        if (!this.isSyntaxValid()) {
            return;
        }

        if (this.isVerifying) {
            return;
        }

        await this.performVerification();
    }

    /**
     * On form submit: block if syntax is invalid.
     */
    validateSubmit(event: Event): void {
        if (!this.isSyntaxValid()) {
            event.preventDefault();
            this.showSyntaxError(true);
        }
    }

    private isSyntaxValid(): boolean {
        if (!this.hasInputTarget) {
            return true;
        }
        const value = this.inputTarget.value.trim();
        if (value === "") {
            return true;
        }
        const lines = value
            .split(/\r\n|\r|\n/)
            .map((s) => s.trim())
            .filter(Boolean);
        for (const line of lines) {
            if (!this.isValidUrl(line)) {
                return false;
            }
        }
        return true;
    }

    private isValidUrl(line: string): boolean {
        try {
            const u = new URL(line);
            return u.protocol === "http:" || u.protocol === "https:";
        } catch {
            return false;
        }
    }

    private getUrlLines(): string[] {
        if (!this.hasInputTarget) {
            return [];
        }
        const value = this.inputTarget.value.trim();
        if (value === "") {
            return [];
        }
        return value
            .split(/\r\n|\r|\n/)
            .map((s) => s.trim())
            .filter(Boolean);
    }

    private showSyntaxError(show: boolean): void {
        if (this.hasSyntaxErrorTarget) {
            this.syntaxErrorTarget.classList.toggle("hidden", !show);
        }
        if (this.hasSyntaxErrorTextTarget) {
            this.syntaxErrorTextTarget.textContent = show ? this.syntaxErrorLabelValue : "";
        }
    }

    private hideSoftStatus(): void {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.add("hidden");
        }
        if (this.hasSuccessTarget) {
            this.successTarget.classList.add("hidden");
        }
        if (this.hasWarningTarget) {
            this.warningTarget.classList.add("hidden");
        }
    }

    private showStatus(which: "spinner" | "success" | "warning"): void {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle("hidden", which !== "spinner");
        }
        if (this.hasSuccessTarget) {
            this.successTarget.classList.toggle("hidden", which !== "success");
        }
        if (this.hasWarningTarget) {
            this.warningTarget.classList.toggle("hidden", which !== "warning");
        }
    }

    private async performVerification(): Promise<void> {
        this.isVerifying = true;
        this.showStatus("spinner");

        const urls = this.getUrlLines();
        let allValid = true;

        try {
            for (const url of urls) {
                const formData = new FormData();
                formData.append("url", url);
                formData.append("_csrf_token", this.csrfTokenValue);

                const response = await fetch(this.verifyUrlValue, {
                    method: "POST",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    body: formData,
                });

                const data = (await response.json()) as { valid?: boolean };
                if (!data.valid) {
                    allValid = false;
                    break;
                }
            }

            if (allValid) {
                this.showStatus("success");
            } else {
                this.showStatus("warning");
            }
        } catch {
            this.showStatus("warning");
        } finally {
            this.isVerifying = false;
        }
    }
}
