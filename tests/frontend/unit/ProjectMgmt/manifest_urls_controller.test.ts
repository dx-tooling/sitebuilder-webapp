import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { Application } from "@hotwired/stimulus";
import ManifestUrlsController from "../../../../src/ProjectMgmt/Presentation/Resources/assets/controllers/manifest_urls_controller.ts";

describe("ManifestUrlsController", () => {
    let application: Application;

    const flushMicrotasks = async (): Promise<void> => {
        await Promise.resolve();
        await Promise.resolve();
    };

    beforeEach(() => {
        document.body.innerHTML = "";
        application = Application.start();
        application.register("manifest-urls", ManifestUrlsController);
    });

    afterEach(() => {
        application.stop();
        vi.restoreAllMocks();
    });

    const createControllerElement = async (initialValue: string = ""): Promise<HTMLFormElement> => {
        const html = `
            <form data-controller="manifest-urls"
                  data-manifest-urls-verify-url-value="/projects/verify-manifest-url"
                  data-manifest-urls-csrf-token-value="csrf-token"
                  data-manifest-urls-syntax-error-label-value="Enter one URL per line."
                  data-action="submit->manifest-urls#validateSubmit">
                <textarea data-manifest-urls-target="input"
                          data-action="blur->manifest-urls#verify input->manifest-urls#checkSyntax">${initialValue}</textarea>
                <div data-manifest-urls-target="status">
                    <div class="hidden" data-manifest-urls-target="syntaxError">
                        <span data-manifest-urls-target="syntaxErrorText"></span>
                    </div>
                    <div class="hidden" data-manifest-urls-target="spinner">Loading...</div>
                    <div class="hidden" data-manifest-urls-target="success">OK</div>
                    <div class="hidden" data-manifest-urls-target="warning">Warning</div>
                </div>
                <button type="submit">Save</button>
            </form>
        `;
        document.body.innerHTML = html;
        await flushMicrotasks();
        return document.body.querySelector("form") as HTMLFormElement;
    };

    it("empty value passes syntax check", async () => {
        await createControllerElement("");
        const textarea = document.querySelector('[data-manifest-urls-target="input"]') as HTMLTextAreaElement;
        const syntaxError = document.querySelector('[data-manifest-urls-target="syntaxError"]') as HTMLElement;

        textarea.dispatchEvent(new Event("input", { bubbles: true }));

        await flushMicrotasks();
        expect(syntaxError.classList.contains("hidden")).toBe(true);
    });

    it("invalid URL fails syntax check and shows error", async () => {
        await createControllerElement("not-a-valid-url");
        const textarea = document.querySelector('[data-manifest-urls-target="input"]') as HTMLTextAreaElement;
        const syntaxError = document.querySelector('[data-manifest-urls-target="syntaxError"]') as HTMLElement;

        textarea.dispatchEvent(new Event("input", { bubbles: true }));

        await flushMicrotasks();
        expect(syntaxError.classList.contains("hidden")).toBe(false);
    });

    it("valid http URL passes syntax check", async () => {
        await createControllerElement("http://example.com/manifest.json");
        const textarea = document.querySelector('[data-manifest-urls-target="input"]') as HTMLTextAreaElement;
        const syntaxError = document.querySelector('[data-manifest-urls-target="syntaxError"]') as HTMLElement;

        textarea.dispatchEvent(new Event("input", { bubbles: true }));

        await flushMicrotasks();
        expect(syntaxError.classList.contains("hidden")).toBe(true);
    });

    it("validateSubmit prevents form submit when syntax is invalid", async () => {
        await createControllerElement("invalid");
        const form = document.querySelector("form") as HTMLFormElement;
        const textarea = document.querySelector('[data-manifest-urls-target="input"]') as HTMLTextAreaElement;

        textarea.dispatchEvent(new Event("input", { bubbles: true }));
        await flushMicrotasks();

        const submitEvent = new Event("submit", {
            bubbles: true,
            cancelable: true,
        });
        form.dispatchEvent(submitEvent);

        expect(submitEvent.defaultPrevented).toBe(true);
    });
});
