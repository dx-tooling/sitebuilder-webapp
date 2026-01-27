import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for S3 credentials verification.
 * Handles blur events on the secret access key input to verify credentials,
 * shows loading/success/error states.
 */
export default class extends Controller {
    static values = {
        verifyUrl: String,
        csrfToken: String,
    };

    static targets = [
        "bucketName",
        "region",
        "accessKeyId",
        "secretAccessKey",
        "iamRoleArn",
        "keyPrefix",
        "status",
        "spinner",
        "success",
        "error",
    ];

    declare readonly verifyUrlValue: string;
    declare readonly csrfTokenValue: string;

    declare readonly hasBucketNameTarget: boolean;
    declare readonly bucketNameTarget: HTMLInputElement;
    declare readonly hasRegionTarget: boolean;
    declare readonly regionTarget: HTMLSelectElement;
    declare readonly hasAccessKeyIdTarget: boolean;
    declare readonly accessKeyIdTarget: HTMLInputElement;
    declare readonly hasSecretAccessKeyTarget: boolean;
    declare readonly secretAccessKeyTarget: HTMLInputElement;
    declare readonly hasIamRoleArnTarget: boolean;
    declare readonly iamRoleArnTarget: HTMLInputElement;
    declare readonly hasKeyPrefixTarget: boolean;
    declare readonly keyPrefixTarget: HTMLInputElement;
    declare readonly hasSpinnerTarget: boolean;
    declare readonly spinnerTarget: HTMLElement;
    declare readonly hasSuccessTarget: boolean;
    declare readonly successTarget: HTMLElement;
    declare readonly hasErrorTarget: boolean;
    declare readonly errorTarget: HTMLElement;

    private isVerifying: boolean = false;

    /**
     * Triggered when the secret access key input loses focus.
     * Only verifies if all required fields are filled.
     */
    async verify(): Promise<void> {
        // Get all field values
        const bucketName = this.hasBucketNameTarget ? this.bucketNameTarget.value.trim() : "";
        const region = this.hasRegionTarget ? this.regionTarget.value.trim() : "";
        const accessKeyId = this.hasAccessKeyIdTarget ? this.accessKeyIdTarget.value.trim() : "";
        const secretAccessKey = this.hasSecretAccessKeyTarget ? this.secretAccessKeyTarget.value.trim() : "";
        const iamRoleArn = this.hasIamRoleArnTarget ? this.iamRoleArnTarget.value.trim() : "";

        // Don't verify if any required field is empty
        if (bucketName === "" || region === "" || accessKeyId === "" || secretAccessKey === "") {
            return;
        }

        // Don't start a new verification if one is in progress
        if (this.isVerifying) {
            return;
        }

        await this.performVerification(bucketName, region, accessKeyId, secretAccessKey, iamRoleArn);
    }

    /**
     * Performs the actual S3 credentials verification.
     */
    private async performVerification(
        bucketName: string,
        region: string,
        accessKeyId: string,
        secretAccessKey: string,
        iamRoleArn: string,
    ): Promise<void> {
        this.isVerifying = true;

        // Show spinner, hide others
        this.showStatus("spinner");

        // Disable inputs during verification
        this.setInputsDisabled(true);

        try {
            const formData = new FormData();
            formData.append("bucket_name", bucketName);
            formData.append("region", region);
            formData.append("access_key_id", accessKeyId);
            formData.append("secret_access_key", secretAccessKey);
            formData.append("iam_role_arn", iamRoleArn);
            formData.append("_csrf_token", this.csrfTokenValue);

            const response = await fetch(this.verifyUrlValue, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: formData,
            });

            const data = (await response.json()) as { valid: boolean };

            if (data.valid) {
                this.showStatus("success");
            } else {
                this.showStatus("error");
            }
        } catch {
            this.showStatus("error");
        } finally {
            this.isVerifying = false;

            // Re-enable inputs
            this.setInputsDisabled(false);
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

    /**
     * Enable or disable all input fields.
     */
    private setInputsDisabled(disabled: boolean): void {
        if (this.hasBucketNameTarget) {
            this.bucketNameTarget.disabled = disabled;
        }
        if (this.hasRegionTarget) {
            this.regionTarget.disabled = disabled;
        }
        if (this.hasAccessKeyIdTarget) {
            this.accessKeyIdTarget.disabled = disabled;
        }
        if (this.hasSecretAccessKeyTarget) {
            this.secretAccessKeyTarget.disabled = disabled;
        }
        if (this.hasIamRoleArnTarget) {
            this.iamRoleArnTarget.disabled = disabled;
        }
        if (this.hasKeyPrefixTarget) {
            this.keyPrefixTarget.disabled = disabled;
        }
    }
}
