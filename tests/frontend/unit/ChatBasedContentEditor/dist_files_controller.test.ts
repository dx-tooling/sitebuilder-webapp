import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import DistFilesController from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/dist_files_controller.ts";

/**
 * Unit tests for the Dist Files Stimulus controller.
 * Tests the polling and rendering of dist HTML files list.
 */

interface DistFile {
    path: string;
    url: string;
}

interface MockControllerState {
    pollUrlValue: string;
    pollIntervalValue: number;
    readOnlyValue: boolean;
    photoBuilderUrlPatternValue: string;
    photoBuilderLabelValue: string;
    editHtmlLabelValue: string;
    previewLabelValue: string;
    hasListTarget: boolean;
    listTarget: HTMLElement | null;
    hasContainerTarget: boolean;
    containerTarget: HTMLElement | null;
    hasPhotoBuilderSectionTarget: boolean;
    photoBuilderSectionTarget: HTMLElement | null;
    hasPhotoBuilderLinksTarget: boolean;
    photoBuilderLinksTarget: HTMLElement | null;
    pollingTimeoutId: ReturnType<typeof setTimeout> | null;
    lastFilesJson: string;
    isActive: boolean;
}

const createController = (
    overrides: Partial<MockControllerState> = {},
    elementOverride?: HTMLElement,
): DistFilesController => {
    const controller = Object.create(DistFilesController.prototype) as DistFilesController;
    const state = controller as unknown as MockControllerState;

    // Default values
    state.pollUrlValue = "/workspace/test-id/dist-files";
    state.pollIntervalValue = 3000;
    state.readOnlyValue = false;
    state.photoBuilderUrlPatternValue = "";
    state.photoBuilderLabelValue = "Generate matching images";
    state.editHtmlLabelValue = "Edit HTML";
    state.previewLabelValue = "Preview";

    // Default targets (not present)
    state.hasListTarget = false;
    state.listTarget = null;
    state.hasContainerTarget = false;
    state.containerTarget = null;
    state.hasPhotoBuilderSectionTarget = false;
    state.photoBuilderSectionTarget = null;
    state.hasPhotoBuilderLinksTarget = false;
    state.photoBuilderLinksTarget = null;

    // Private state
    state.pollingTimeoutId = null;
    state.lastFilesJson = "";
    state.isActive = false;

    // Apply overrides (excluding element)
    Object.assign(state, overrides);

    // Element is a getter in Stimulus Controller, so we need to define it as a property
    const controllerElement = elementOverride ?? document.createElement("div");
    Object.defineProperty(controller, "element", {
        get: () => controllerElement,
        configurable: true,
    });

    return controller;
};

const createFullController = (
    overrides: Partial<MockControllerState> = {},
): {
    controller: DistFilesController;
    elements: {
        list: HTMLUListElement;
        container: HTMLElement;
        photoBuilderSection: HTMLElement;
        photoBuilderLinks: HTMLElement;
        controllerElement: HTMLElement;
    };
} => {
    const list = document.createElement("ul");
    const container = document.createElement("div");
    container.classList.add("hidden");
    const photoBuilderSection = document.createElement("div");
    photoBuilderSection.classList.add("hidden");
    const photoBuilderLinks = document.createElement("div");
    const controllerElement = document.createElement("div");

    const controller = createController(
        {
            hasListTarget: true,
            listTarget: list,
            hasContainerTarget: true,
            containerTarget: container,
            hasPhotoBuilderSectionTarget: true,
            photoBuilderSectionTarget: photoBuilderSection,
            hasPhotoBuilderLinksTarget: true,
            photoBuilderLinksTarget: photoBuilderLinks,
            ...overrides,
        },
        controllerElement,
    );

    return {
        controller,
        elements: {
            list,
            container,
            photoBuilderSection,
            photoBuilderLinks,
            controllerElement,
        },
    };
};

/**
 * Helper to run a single poll cycle without triggering infinite loops.
 * Uses advanceTimersByTimeAsync with a small increment and flushes promises.
 */
async function runSinglePollCycle(): Promise<void> {
    // Flush the microtask queue to allow fetch promises to resolve
    await vi.advanceTimersByTimeAsync(0);
}

describe("DistFilesController", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        vi.useFakeTimers();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    describe("renderFiles", () => {
        it("should hide container when files array is empty", async () => {
            const { controller, elements } = createFullController();
            elements.container.classList.remove("hidden");

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ files: [] }), { status: 200 }),
            );

            controller.connect();
            await runSinglePollCycle();

            expect(elements.container.classList.contains("hidden")).toBe(true);

            controller.disconnect();
        });

        it("should show container when files are present", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-1/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.container.classList.contains("hidden")).toBe(false);

            controller.disconnect();
        });

        it("should clear list before rendering", async () => {
            const { controller, elements } = createFullController();
            elements.list.innerHTML = "<li>Old item</li>";

            const files: DistFile[] = [{ path: "new.html", url: "/ws/dist/new.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.list.children.length).toBe(1);
            expect(elements.list.innerHTML).not.toContain("Old item");

            controller.disconnect();
        });

        it("should create edit link for each file", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const editLink = elements.list.querySelector('a[title="Edit HTML"]');
            expect(editLink).not.toBeNull();
            expect(editLink?.getAttribute("href")).toBe("#");

            controller.disconnect();
        });

        it("should create preview link for each file", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const previewLink = elements.list.querySelector('a[target="_blank"]');
            expect(previewLink).not.toBeNull();
            expect(previewLink?.getAttribute("href")).toBe("/workspaces/ws-123/dist/index.html");

            controller.disconnect();
        });

        it("should not include PhotoBuilder camera icon in file rows", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-123?page=__PAGE_PATH__",
            });
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            // File rows should only have edit + preview links, no camera icon
            const fileRowLinks = elements.list.querySelectorAll("a");
            Array.from(fileRowLinks).forEach((link) => {
                expect(link.getAttribute("title")).not.toBe("Generate matching images");
            });

            controller.disconnect();
        });

        it("should display file path text", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "pages/about.html", url: "/ws/dist/pages/about.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.list.textContent).toContain("pages/about.html");

            controller.disconnect();
        });

        it("should render multiple files", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [
                { path: "index.html", url: "/ws/dist/index.html" },
                { path: "about.html", url: "/ws/dist/about.html" },
                { path: "contact.html", url: "/ws/dist/contact.html" },
            ];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.list.children.length).toBe(3);

            controller.disconnect();
        });
    });

    describe("readOnly mode", () => {
        it("should show preview link when readOnly is true", async () => {
            const { controller, elements } = createFullController({ readOnlyValue: true });

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const previewLink = elements.list.querySelector('a[target="_blank"]');
            expect(previewLink).not.toBeNull();
            expect(previewLink?.getAttribute("href")).toBe("/workspaces/ws-123/dist/index.html");

            controller.disconnect();
        });

        it("should show edit link when readOnly is false (default)", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const editLink = elements.list.querySelector('a[title="Edit HTML"]');
            expect(editLink).not.toBeNull();

            controller.disconnect();
        });

        it("should not show edit links for any file when readOnly is true", async () => {
            const { controller, elements } = createFullController({ readOnlyValue: true });

            const files: DistFile[] = [
                { path: "index.html", url: "/workspaces/ws-123/dist/index.html" },
                { path: "about.html", url: "/workspaces/ws-123/dist/about.html" },
                { path: "contact.html", url: "/workspaces/ws-123/dist/contact.html" },
            ];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const editLinks = elements.list.querySelectorAll('a[title="Edit HTML"]');
            expect(editLinks.length).toBe(0);

            // Preview button links + clickable filenames should all be present (2 per file)
            const previewLinks = elements.list.querySelectorAll('a[target="_blank"]');
            expect(previewLinks.length).toBe(6);

            controller.disconnect();
        });
    });

    describe("openHtmlEditor", () => {
        it("should dispatch html-editor:open custom event", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-abc/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            const eventHandler = vi.fn();
            elements.controllerElement.addEventListener("html-editor:open", eventHandler);

            controller.connect();
            await runSinglePollCycle();

            // Find and click the edit link
            const editLink = elements.list.querySelector('a[title="Edit HTML"]') as HTMLAnchorElement;
            expect(editLink).not.toBeNull();

            editLink.click();

            expect(eventHandler).toHaveBeenCalled();

            controller.disconnect();
        });

        it("should include correct path in event detail", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [
                { path: "pages/contact.html", url: "/workspaces/ws-abc/dist/pages/contact.html" },
            ];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            let receivedDetail: { path: string } | null = null;
            elements.controllerElement.addEventListener("html-editor:open", ((event: CustomEvent<{ path: string }>) => {
                receivedDetail = event.detail;
            }) as EventListener);

            controller.connect();
            await runSinglePollCycle();

            const editLink = elements.list.querySelector('a[title="Edit HTML"]') as HTMLAnchorElement;
            editLink.click();

            // Path is extracted from URL: /workspaces/{workspaceId}/{fullPath}
            expect(receivedDetail).not.toBeNull();
            expect(receivedDetail!.path).toBe("dist/pages/contact.html");

            controller.disconnect();
        });

        it("should prevent default on edit link click", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-abc/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const editLink = elements.list.querySelector('a[title="Edit HTML"]') as HTMLAnchorElement;

            const clickEvent = new MouseEvent("click", { bubbles: true, cancelable: true });
            const preventDefaultSpy = vi.spyOn(clickEvent, "preventDefault");

            editLink.dispatchEvent(clickEvent);

            expect(preventDefaultSpy).toHaveBeenCalled();

            controller.disconnect();
        });

        it("should dispatch event with bubbles: true", async () => {
            const { controller, elements } = createFullController();
            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-abc/dist/index.html" }];

            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            let eventBubbles = false;
            document.body.appendChild(elements.controllerElement);
            document.body.addEventListener("html-editor:open", () => {
                eventBubbles = true;
            });

            controller.connect();
            await runSinglePollCycle();

            const editLink = elements.list.querySelector('a[title="Edit HTML"]') as HTMLAnchorElement;
            editLink.click();

            expect(eventBubbles).toBe(true);

            controller.disconnect();
        });
    });

    describe("photoBuilder CTA", () => {
        it("should show PhotoBuilder section and render links when photoBuilderUrlPattern is set", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-123?page=__PAGE_PATH__&conversationId=conv-456",
            });

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            // Section should be visible
            expect(elements.photoBuilderSection.classList.contains("hidden")).toBe(false);

            // Should have a link in the photoBuilderLinks container
            const links = elements.photoBuilderLinks.querySelectorAll("a");
            expect(links.length).toBe(1);
            expect(links[0].getAttribute("href")).toBe(
                "/photo-builder/ws-123?page=" + encodeURIComponent("index.html") + "&conversationId=conv-456",
            );
            expect(links[0].textContent).toContain("index.html");

            controller.disconnect();
        });

        it("should keep PhotoBuilder section hidden when photoBuilderUrlPattern is empty", async () => {
            const { controller, elements } = createFullController();

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.photoBuilderSection.classList.contains("hidden")).toBe(true);
            expect(elements.photoBuilderLinks.children.length).toBe(0);

            controller.disconnect();
        });

        it("should keep PhotoBuilder section hidden in readOnly mode even with URL pattern", async () => {
            const { controller, elements } = createFullController({
                readOnlyValue: true,
                photoBuilderUrlPatternValue: "/photo-builder/ws-123?page=__PAGE_PATH__",
            });

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            expect(elements.photoBuilderSection.classList.contains("hidden")).toBe(true);
            expect(elements.photoBuilderLinks.children.length).toBe(0);

            controller.disconnect();
        });

        it("should use custom label as link title", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-123?page=__PAGE_PATH__",
                photoBuilderLabelValue: "Custom label",
            });

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-123/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const link = elements.photoBuilderLinks.querySelector('a[title="Custom label"]');
            expect(link).not.toBeNull();

            controller.disconnect();
        });

        it("should encode page path in PhotoBuilder URL", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-1?page=__PAGE_PATH__",
            });

            const files: DistFile[] = [
                { path: "pages/about us.html", url: "/workspaces/ws-1/dist/pages/about us.html" },
            ];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const link = elements.photoBuilderLinks.querySelector("a") as HTMLAnchorElement;
            expect(link).not.toBeNull();
            expect(link.href).toContain(encodeURIComponent("pages/about us.html"));

            controller.disconnect();
        });

        it("should render one link per page file in the PhotoBuilder section", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-1?page=__PAGE_PATH__",
            });

            const files: DistFile[] = [
                { path: "index.html", url: "/workspaces/ws-1/dist/index.html" },
                { path: "about.html", url: "/workspaces/ws-1/dist/about.html" },
                { path: "contact.html", url: "/workspaces/ws-1/dist/contact.html" },
            ];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            const links = elements.photoBuilderLinks.querySelectorAll("a");
            expect(links.length).toBe(3);
            expect(links[0].textContent).toContain("index.html");
            expect(links[1].textContent).toContain("about.html");
            expect(links[2].textContent).toContain("contact.html");

            controller.disconnect();
        });

        it("should hide PhotoBuilder section when files become empty", async () => {
            const { controller, elements } = createFullController({
                photoBuilderUrlPatternValue: "/photo-builder/ws-1?page=__PAGE_PATH__",
            });

            // First poll with files
            const fetchMock = vi.spyOn(globalThis, "fetch");
            fetchMock.mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ files: [{ path: "index.html", url: "/workspaces/ws-1/dist/index.html" }] }),
                    { status: 200 },
                ),
            );

            controller.connect();
            await runSinglePollCycle();

            expect(elements.photoBuilderSection.classList.contains("hidden")).toBe(false);

            // Second poll with no files
            fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ files: [] }), { status: 200 }));

            await vi.advanceTimersByTimeAsync(3000);

            expect(elements.container.classList.contains("hidden")).toBe(true);

            controller.disconnect();
        });

        it("should gracefully handle missing photoBuilder targets", async () => {
            const list = document.createElement("ul");
            const container = document.createElement("div");
            container.classList.add("hidden");
            const controllerElement = document.createElement("div");

            const controller = createController(
                {
                    hasListTarget: true,
                    listTarget: list,
                    hasContainerTarget: true,
                    containerTarget: container,
                    hasPhotoBuilderSectionTarget: false,
                    hasPhotoBuilderLinksTarget: false,
                    photoBuilderUrlPatternValue: "/photo-builder/ws-1?page=__PAGE_PATH__",
                },
                controllerElement,
            );

            const files: DistFile[] = [{ path: "index.html", url: "/workspaces/ws-1/dist/index.html" }];
            vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(JSON.stringify({ files }), { status: 200 }));

            controller.connect();
            await runSinglePollCycle();

            // Should not throw, file list still renders
            expect(list.children.length).toBe(1);

            controller.disconnect();
        });
    });

    describe("edge cases", () => {
        it("should not render without list target", async () => {
            const controller = createController({
                hasListTarget: false,
                hasContainerTarget: true,
                containerTarget: document.createElement("div"),
            });

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ files: [{ path: "test.html", url: "/test" }] }), { status: 200 }),
            );

            controller.connect();
            await runSinglePollCycle();

            // Should not throw
            controller.disconnect();
        });

        it("should not render without container target", async () => {
            const controller = createController({
                hasListTarget: true,
                listTarget: document.createElement("ul"),
                hasContainerTarget: false,
            });

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ files: [{ path: "test.html", url: "/test" }] }), { status: 200 }),
            );

            controller.connect();
            await runSinglePollCycle();

            // Should not throw
            controller.disconnect();
        });

        it("should handle non-OK response gracefully", async () => {
            const { controller, elements } = createFullController();

            vi.spyOn(globalThis, "fetch").mockResolvedValue(
                new Response(JSON.stringify({ error: "Not found" }), { status: 404 }),
            );

            controller.connect();
            await runSinglePollCycle();

            // Should not render anything and not throw
            expect(elements.list.children.length).toBe(0);

            controller.disconnect();
        });
    });
});
