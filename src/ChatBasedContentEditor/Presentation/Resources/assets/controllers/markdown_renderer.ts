/**
 * Lightweight markdown renderer for chat messages.
 *
 * Handles common markdown patterns with XSS prevention.
 * Designed to work with streaming content where markdown may be incomplete.
 */

/**
 * Escapes HTML special characters to prevent XSS.
 */
export function escapeHtmlForMarkdown(text: string): string {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/**
 * Renders a fenced code block with optional language highlighting hint.
 */
function renderCodeBlock(code: string, language: string): string {
    const escapedCode = escapeHtmlForMarkdown(code);
    const langClass = language ? ` class="language-${escapeHtmlForMarkdown(language)}"` : "";
    return `<pre class="bg-dark-100 dark:bg-dark-800 rounded-md p-3 overflow-x-auto my-2"><code${langClass}>${escapedCode}</code></pre>`;
}

/**
 * Renders inline code.
 */
function renderInlineCode(code: string): string {
    return `<code class="bg-dark-100 dark:bg-dark-700 px-1.5 py-0.5 rounded text-sm font-mono">${escapeHtmlForMarkdown(code)}</code>`;
}

/**
 * Placeholder token for inline code to prevent further processing.
 */
const CODE_PLACEHOLDER_PREFIX = "\x00CODE_";
const CODE_PLACEHOLDER_SUFFIX = "_EDOC\x00";

/**
 * Processes inline markdown elements (bold, italic, code, links).
 * Text should NOT be escaped yet - this function handles escaping.
 */
function processInlineMarkdown(text: string): string {
    // First, extract and protect inline code blocks
    const codeBlocks: string[] = [];
    text = text.replace(/(?<!`)`([^`\n]+?)`(?!`)/g, (_, code) => {
        const index = codeBlocks.length;
        codeBlocks.push(code);
        return `${CODE_PLACEHOLDER_PREFIX}${index}${CODE_PLACEHOLDER_SUFFIX}`;
    });

    // Now escape HTML in the remaining text
    text = escapeHtmlForMarkdown(text);

    // Bold: **text** or __text__ (allow any characters except newlines)
    text = text.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    text = text.replace(/__(.+?)__/g, "<strong>$1</strong>");

    // Italic: *text* or _text_ (but not inside words for underscore)
    text = text.replace(/(?<!\*)\*([^*\n]+?)\*(?!\*)/g, "<em>$1</em>");
    text = text.replace(/(?<![a-zA-Z0-9])_([^_\n]+?)_(?![a-zA-Z0-9])/g, "<em>$1</em>");

    // Links: [text](url)
    text = text.replace(/\[([^\]]+?)\]\(([^)]+?)\)/g, (_, linkText, url) => {
        // Validate URL to prevent javascript: and other dangerous protocols
        const safeUrl = sanitizeUrl(url);
        return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" class="text-primary-600 dark:text-primary-400 underline hover:no-underline">${linkText}</a>`;
    });

    // Restore code blocks (with proper escaping)
    text = text.replace(new RegExp(`${CODE_PLACEHOLDER_PREFIX}(\\d+)${CODE_PLACEHOLDER_SUFFIX}`, "g"), (_, index) => {
        const code = codeBlocks[parseInt(index, 10)];
        return renderInlineCode(code);
    });

    return text;
}

/**
 * Sanitizes a URL to prevent XSS via javascript: or data: protocols.
 */
function sanitizeUrl(url: string): string {
    const trimmed = url.trim().toLowerCase();
    if (trimmed.startsWith("javascript:") || trimmed.startsWith("data:") || trimmed.startsWith("vbscript:")) {
        return "#";
    }
    return escapeHtmlForMarkdown(url);
}

/**
 * Parses and renders a list (ordered or unordered).
 */
function renderList(lines: string[], startIndex: number): { html: string; endIndex: number } {
    const firstLine = lines[startIndex];
    const isOrdered = /^\d+\.\s/.test(firstLine);
    const listItems: string[] = [];
    let i = startIndex;

    const listPattern = isOrdered ? /^(\d+)\.\s(.*)$/ : /^[-*+]\s(.*)$/;

    while (i < lines.length) {
        const line = lines[i];
        const match = line.match(listPattern);
        if (!match) {
            break;
        }

        const content = isOrdered ? match[2] : match[1];
        listItems.push(`<li>${processInlineMarkdown(content)}</li>`);
        i++;
    }

    const tag = isOrdered ? "ol" : "ul";
    const listClass = isOrdered ? "list-decimal list-inside my-2 space-y-1" : "list-disc list-inside my-2 space-y-1";

    return {
        html: `<${tag} class="${listClass}">${listItems.join("")}</${tag}>`,
        endIndex: i - 1,
    };
}

/**
 * Checks if a line is a list item.
 */
function isListItem(line: string): boolean {
    return /^[-*+]\s/.test(line) || /^\d+\.\s/.test(line);
}

/**
 * Checks if a line is a header.
 */
function isHeader(line: string): boolean {
    return /^#{1,6}\s/.test(line);
}

/**
 * Renders a header line.
 */
function renderHeader(line: string): string {
    const match = line.match(/^(#{1,6})\s(.*)$/);
    if (!match) {
        return `<p>${processInlineMarkdown(line)}</p>`;
    }

    const level = match[1].length;
    const content = match[2];

    const sizeClasses: Record<number, string> = {
        1: "text-xl font-bold my-3",
        2: "text-lg font-bold my-2",
        3: "text-base font-semibold my-2",
        4: "text-sm font-semibold my-1",
        5: "text-sm font-medium my-1",
        6: "text-xs font-medium my-1",
    };

    const classes = sizeClasses[level] || sizeClasses[6];
    return `<h${level} class="${classes}">${processInlineMarkdown(content)}</h${level}>`;
}

/**
 * Configuration options for markdown rendering.
 */
export interface MarkdownRenderOptions {
    /**
     * When true, handles potentially incomplete markdown more gracefully.
     * For example, unclosed code blocks are rendered as-is rather than
     * treating all subsequent content as code.
     */
    streaming?: boolean;
}

/**
 * Renders markdown text to HTML.
 *
 * Supports:
 * - Bold: **text** or __text__
 * - Italic: *text* or _text_
 * - Inline code: `code`
 * - Code blocks: ```language ... ```
 * - Headers: # to ######
 * - Unordered lists: - item, * item, + item
 * - Ordered lists: 1. item
 * - Links: [text](url)
 * - Paragraphs and line breaks
 *
 * @param markdown The markdown text to render
 * @param options Rendering options
 * @returns HTML string
 */
export function renderMarkdown(markdown: string, options: MarkdownRenderOptions = {}): string {
    if (!markdown) {
        return "";
    }

    const { streaming = false } = options;
    const lines = markdown.split("\n");
    const output: string[] = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        // Check for fenced code block
        if (line.startsWith("```")) {
            const language = line.slice(3).trim();
            const codeLines: string[] = [];
            i++;

            // Find closing fence
            let foundClosing = false;
            while (i < lines.length) {
                if (lines[i].startsWith("```")) {
                    foundClosing = true;
                    i++;
                    break;
                }
                codeLines.push(lines[i]);
                i++;
            }

            if (foundClosing || !streaming) {
                // Render as code block
                output.push(renderCodeBlock(codeLines.join("\n"), language));
            } else {
                // In streaming mode with unclosed code block, render what we have
                // but indicate it's still being streamed
                output.push(renderCodeBlock(codeLines.join("\n"), language));
            }
            continue;
        }

        // Check for header
        if (isHeader(line)) {
            output.push(renderHeader(line));
            i++;
            continue;
        }

        // Check for list
        if (isListItem(line)) {
            const listResult = renderList(lines, i);
            output.push(listResult.html);
            i = listResult.endIndex + 1;
            continue;
        }

        // Check for horizontal rule
        if (/^[-*_]{3,}$/.test(line.trim())) {
            output.push('<hr class="my-4 border-dark-300 dark:border-dark-600">');
            i++;
            continue;
        }

        // Check for blockquote
        if (line.startsWith("> ")) {
            const quoteLines: string[] = [];
            while (i < lines.length && lines[i].startsWith("> ")) {
                quoteLines.push(lines[i].slice(2));
                i++;
            }
            const quoteContent = quoteLines.map((l) => processInlineMarkdown(l)).join("<br>");
            output.push(
                `<blockquote class="border-l-4 border-dark-300 dark:border-dark-600 pl-4 my-2 italic text-dark-600 dark:text-dark-400">${quoteContent}</blockquote>`,
            );
            continue;
        }

        // Empty line (paragraph break)
        if (line.trim() === "") {
            i++;
            continue;
        }

        // Regular paragraph - collect consecutive non-special lines
        const paragraphLines: string[] = [];
        while (
            i < lines.length &&
            lines[i].trim() !== "" &&
            !lines[i].startsWith("```") &&
            !isHeader(lines[i]) &&
            !isListItem(lines[i]) &&
            !lines[i].startsWith("> ") &&
            !/^[-*_]{3,}$/.test(lines[i].trim())
        ) {
            paragraphLines.push(lines[i]);
            i++;
        }

        if (paragraphLines.length > 0) {
            const content = paragraphLines.map((l) => processInlineMarkdown(l)).join("<br>");
            output.push(`<p class="my-1">${content}</p>`);
        }
    }

    return output.join("");
}

/**
 * Checks if a string contains any markdown syntax.
 * Useful for deciding whether to render as markdown or plain text.
 */
export function containsMarkdown(text: string): boolean {
    // Check for common markdown patterns
    const patterns = [
        /\*\*[^*]+\*\*/, // Bold
        /\*[^*]+\*/, // Italic
        /__[^_]+__/, // Bold underscore
        /_[^_]+_/, // Italic underscore
        /`[^`]+`/, // Inline code
        /```/, // Code block
        /^#{1,6}\s/m, // Header
        /^[-*+]\s/m, // Unordered list
        /^\d+\.\s/m, // Ordered list
        /\[[^\]]+\]\([^)]+\)/, // Link
    ];

    return patterns.some((pattern) => pattern.test(text));
}
