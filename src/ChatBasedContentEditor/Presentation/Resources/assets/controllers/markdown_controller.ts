import { Controller } from "@hotwired/stimulus";
import { renderMarkdown } from "./markdown_renderer.ts";

/**
 * Stimulus controller for rendering markdown content.
 *
 * This controller manages a markdown source and renders it to HTML.
 * It's designed to work with streaming content where markdown may be
 * appended incrementally.
 *
 * Usage:
 * ```html
 * <div data-controller="markdown"
 *      data-markdown-streaming-value="true">
 * </div>
 * ```
 *
 * The controller exposes methods to append and set content programmatically:
 * - `appendContent(text: string)`: Append text to the markdown source
 * - `setContent(text: string)`: Replace the entire markdown source
 * - `getContent(): string`: Get the current raw markdown content
 */
export default class MarkdownController extends Controller {
    static values = {
        /**
         * When true, the renderer handles potentially incomplete markdown
         * more gracefully (e.g., unclosed code blocks).
         */
        streaming: { type: Boolean, default: true },
    };

    declare readonly streamingValue: boolean;

    /**
     * The raw markdown content that will be rendered.
     */
    private rawContent: string = "";

    /**
     * Whether a render is already scheduled (for debouncing rapid updates).
     */
    private renderScheduled: boolean = false;

    connect(): void {
        // Initial render if there's already content
        if (this.element.textContent) {
            this.rawContent = this.element.textContent;
            this.render();
        }
    }

    /**
     * Appends text to the markdown content and re-renders.
     * This is the primary method for streaming content.
     */
    appendContent(text: string): void {
        this.rawContent += text;
        this.scheduleRender();
    }

    /**
     * Sets the entire markdown content and re-renders.
     */
    setContent(text: string): void {
        this.rawContent = text;
        this.scheduleRender();
    }

    /**
     * Returns the current raw markdown content.
     */
    getContent(): string {
        return this.rawContent;
    }

    /**
     * Clears all content.
     */
    clear(): void {
        this.rawContent = "";
        (this.element as HTMLElement).innerHTML = "";
    }

    /**
     * Schedules a render on the next animation frame.
     * This debounces rapid updates that can occur during streaming.
     */
    private scheduleRender(): void {
        if (this.renderScheduled) {
            return;
        }

        this.renderScheduled = true;
        requestAnimationFrame(() => {
            this.render();
            this.renderScheduled = false;
        });
    }

    /**
     * Renders the current markdown content to HTML.
     */
    private render(): void {
        const html = renderMarkdown(this.rawContent, {
            streaming: this.streamingValue,
        });

        (this.element as HTMLElement).innerHTML = html;

        // Dispatch an event for parent controllers that may need to react
        this.dispatch("rendered", {
            detail: {
                contentLength: this.rawContent.length,
            },
        });
    }
}
