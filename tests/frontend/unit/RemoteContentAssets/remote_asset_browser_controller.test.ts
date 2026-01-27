import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { Application } from "@hotwired/stimulus";
import RemoteAssetBrowserController from "../../../../src/RemoteContentAssets/Presentation/Resources/assets/controllers/remote_asset_browser_controller.ts";

describe("RemoteAssetBrowserController", () => {
    let application: Application;

    beforeEach(() => {
        document.body.innerHTML = "";
        application = Application.start();
        application.register("remote-asset-browser", RemoteAssetBrowserController);
        vi.stubGlobal("fetch", vi.fn());
    });

    afterEach(() => {
        application.stop();
        vi.restoreAllMocks();
    });

    const createControllerElement = async (
        fetchUrl: string = "/api/projects/test-id/remote-assets",
    ): Promise<HTMLElement> => {
        const html = `
            <div data-controller="remote-asset-browser"
                 data-remote-asset-browser-fetch-url-value="${fetchUrl}"
                 data-remote-asset-browser-window-size-value="50"
                 data-remote-asset-browser-add-to-chat-label-value="Add to chat"
                 data-remote-asset-browser-open-in-new-tab-label-value="Open in new tab">
                <input type="text"
                       data-remote-asset-browser-target="search"
                       data-action="input->remote-asset-browser#filter"
                       placeholder="Search...">
                <div data-remote-asset-browser-target="loading">Loading...</div>
                <div data-remote-asset-browser-target="empty" class="hidden">No assets</div>
                <span data-remote-asset-browser-target="count"></span>
                <div data-remote-asset-browser-target="list" class="overflow-y-auto" style="max-height: 320px;"></div>
            </div>
        `;
        document.body.innerHTML = html;
        await new Promise((resolve) => setTimeout(resolve, 50));

        return document.body.querySelector('[data-controller="remote-asset-browser"]') as HTMLElement;
    };

    it("fetches assets on connect", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/image.jpg"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        expect(mockFetch).toHaveBeenCalledWith("/api/projects/test-id/remote-assets", {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
    });

    it("shows empty state when no assets returned", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: [] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const emptyEl = document.querySelector('[data-remote-asset-browser-target="empty"]') as HTMLElement;
        const loadingEl = document.querySelector('[data-remote-asset-browser-target="loading"]') as HTMLElement;

        expect(emptyEl.classList.contains("hidden")).toBe(false);
        expect(loadingEl.classList.contains("hidden")).toBe(true);
    });

    it("shows count when assets are returned", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: [
                        "https://example.com/image1.jpg",
                        "https://example.com/image2.png",
                        "https://example.com/doc.pdf",
                    ],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const countEl = document.querySelector('[data-remote-asset-browser-target="count"]') as HTMLElement;
        expect(countEl.textContent).toBe("(3)");
    });

    it("renders asset items in the list", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: ["https://example.com/image.jpg", "https://example.com/document.pdf"],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        // Each item has an "Add to chat" button
        const items = listEl.querySelectorAll('button[title="Add to chat"]');

        expect(items.length).toBe(2);
    });

    it("renders image preview for image URLs", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/photo.jpg"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        const img = listEl.querySelector("img") as HTMLImageElement;

        expect(img).not.toBeNull();
        expect(img.src).toBe("https://example.com/photo.jpg");
        expect(img.loading).toBe("lazy");
    });

    it("renders file icon for non-image URLs", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/document.pdf"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        const svg = listEl.querySelector("svg") as SVGElement;

        expect(svg).not.toBeNull();
    });

    it("dispatches insert event when row is clicked", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/image.jpg"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        const controller = await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const eventListener = vi.fn();
        controller.addEventListener("remote-asset-browser:insert", eventListener);

        // Click on the row (not on a link or button)
        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        const row = listEl.querySelector("div.flex.items-center.cursor-pointer") as HTMLElement;
        row.click();

        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(eventListener).toHaveBeenCalled();
        const event = eventListener.mock.calls[0][0] as CustomEvent;
        expect(event.detail.url).toBe("https://example.com/image.jpg");
    });

    it("dispatches insert event when add button is clicked", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/image.jpg"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        const controller = await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const eventListener = vi.fn();
        controller.addEventListener("remote-asset-browser:insert", eventListener);

        const addButton = document.querySelector('button[title="Add to chat"]') as HTMLButtonElement;
        addButton.click();

        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(eventListener).toHaveBeenCalled();
        const event = eventListener.mock.calls[0][0] as CustomEvent;
        expect(event.detail.url).toBe("https://example.com/image.jpg");
    });

    it("does not dispatch insert event when filename link is clicked", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: ["https://example.com/image.jpg"] }),
        });
        vi.stubGlobal("fetch", mockFetch);

        const controller = await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const eventListener = vi.fn();
        controller.addEventListener("remote-asset-browser:insert", eventListener);

        // Click on the filename link (should open URL, not add to chat)
        const filenameLink = document.querySelector("a.truncate") as HTMLAnchorElement;
        filenameLink.click();

        await new Promise((resolve) => setTimeout(resolve, 50));

        // Event should NOT have been dispatched because stopPropagation was called
        expect(eventListener).not.toHaveBeenCalled();
    });

    it("extracts filename from URL correctly", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: ["https://cdn.example.com/assets/images/photo.jpg"],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        // The filename link has the truncate class
        const link = listEl.querySelector("a.truncate") as HTMLAnchorElement;

        expect(link.textContent).toBe("photo.jpg");
        expect(link.href).toBe("https://cdn.example.com/assets/images/photo.jpg");
        expect(link.target).toBe("_blank");
    });

    it("handles fetch error gracefully", async () => {
        const mockFetch = vi.fn().mockRejectedValue(new Error("Network error"));
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const emptyEl = document.querySelector('[data-remote-asset-browser-target="empty"]') as HTMLElement;
        const loadingEl = document.querySelector('[data-remote-asset-browser-target="loading"]') as HTMLElement;

        expect(emptyEl.classList.contains("hidden")).toBe(false);
        expect(loadingEl.classList.contains("hidden")).toBe(true);
    });

    it("handles non-ok response gracefully", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 404,
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const emptyEl = document.querySelector('[data-remote-asset-browser-target="empty"]') as HTMLElement;
        expect(emptyEl.classList.contains("hidden")).toBe(false);
    });

    it("recognizes various image extensions", async () => {
        const imageUrls = [
            "https://example.com/a.jpg",
            "https://example.com/b.jpeg",
            "https://example.com/c.png",
            "https://example.com/d.gif",
            "https://example.com/e.webp",
            "https://example.com/f.svg",
            "https://example.com/g.avif",
        ];

        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ urls: imageUrls }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;
        const images = listEl.querySelectorAll("img");

        // All URLs should render as images
        expect(images.length).toBe(7);
    });

    it("filters assets by search query", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: [
                        "https://example.com/photo.jpg",
                        "https://example.com/banner.png",
                        "https://example.com/logo.svg",
                    ],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const searchInput = document.querySelector('[data-remote-asset-browser-target="search"]') as HTMLInputElement;
        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;

        // Initially shows all 3 items
        expect(listEl.querySelectorAll('button[title="Add to chat"]').length).toBe(3);

        // Type search query
        searchInput.value = "logo";
        searchInput.dispatchEvent(new Event("input", { bubbles: true }));

        await new Promise((resolve) => setTimeout(resolve, 50));

        // Now shows only 1 item
        expect(listEl.querySelectorAll('button[title="Add to chat"]').length).toBe(1);
    });

    it("shows filtered count when searching", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: [
                        "https://example.com/photo.jpg",
                        "https://example.com/banner.png",
                        "https://example.com/logo.svg",
                    ],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const searchInput = document.querySelector('[data-remote-asset-browser-target="search"]') as HTMLInputElement;
        const countEl = document.querySelector('[data-remote-asset-browser-target="count"]') as HTMLElement;

        // Initially shows total count
        expect(countEl.textContent).toBe("(3)");

        // Type search query
        searchInput.value = "photo";
        searchInput.dispatchEvent(new Event("input", { bubbles: true }));

        await new Promise((resolve) => setTimeout(resolve, 50));

        // Shows filtered/total count
        expect(countEl.textContent).toBe("(1/3)");
    });

    it("shows empty state when search has no matches", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: ["https://example.com/photo.jpg", "https://example.com/banner.png"],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const searchInput = document.querySelector('[data-remote-asset-browser-target="search"]') as HTMLInputElement;
        const emptyEl = document.querySelector('[data-remote-asset-browser-target="empty"]') as HTMLElement;

        // Type search query that matches nothing
        searchInput.value = "nonexistent";
        searchInput.dispatchEvent(new Event("input", { bubbles: true }));

        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(emptyEl.classList.contains("hidden")).toBe(false);
    });

    it("clears filter when search is emptied", async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    urls: ["https://example.com/photo.jpg", "https://example.com/banner.png"],
                }),
        });
        vi.stubGlobal("fetch", mockFetch);

        await createControllerElement();
        await new Promise((resolve) => setTimeout(resolve, 100));

        const searchInput = document.querySelector('[data-remote-asset-browser-target="search"]') as HTMLInputElement;
        const listEl = document.querySelector('[data-remote-asset-browser-target="list"]') as HTMLElement;

        // Filter down to 1 item
        searchInput.value = "photo";
        searchInput.dispatchEvent(new Event("input", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 50));
        expect(listEl.querySelectorAll('button[title="Add to chat"]').length).toBe(1);

        // Clear search
        searchInput.value = "";
        searchInput.dispatchEvent(new Event("input", { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 50));

        // All items visible again
        expect(listEl.querySelectorAll('button[title="Add to chat"]').length).toBe(2);
    });
});
