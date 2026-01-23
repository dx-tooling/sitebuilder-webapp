import { describe, it, expect } from "vitest";
import {
    renderMarkdown,
    escapeHtmlForMarkdown,
    containsMarkdown,
} from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/markdown_renderer.ts";

describe("markdown_renderer", () => {
    describe("escapeHtmlForMarkdown", () => {
        it("should escape HTML special characters", () => {
            expect(escapeHtmlForMarkdown("<script>alert('xss')</script>")).toBe(
                "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;",
            );
        });

        it("should escape ampersands", () => {
            expect(escapeHtmlForMarkdown("foo & bar")).toBe("foo &amp; bar");
        });

        it("should escape quotes", () => {
            expect(escapeHtmlForMarkdown('say "hello"')).toBe("say &quot;hello&quot;");
        });

        it("should handle empty string", () => {
            expect(escapeHtmlForMarkdown("")).toBe("");
        });

        it("should preserve normal text", () => {
            expect(escapeHtmlForMarkdown("Hello, world!")).toBe("Hello, world!");
        });
    });

    describe("renderMarkdown", () => {
        describe("basic text", () => {
            it("should render plain text in a paragraph", () => {
                const result = renderMarkdown("Hello, world!");
                expect(result).toContain("<p");
                expect(result).toContain("Hello, world!");
                expect(result).toContain("</p>");
            });

            it("should handle empty string", () => {
                expect(renderMarkdown("")).toBe("");
            });

            it("should escape HTML in plain text", () => {
                const result = renderMarkdown("<script>alert('xss')</script>");
                expect(result).not.toContain("<script>");
                expect(result).toContain("&lt;script&gt;");
            });
        });

        describe("bold text", () => {
            it("should render **text** as bold", () => {
                const result = renderMarkdown("This is **bold** text");
                expect(result).toContain("<strong>bold</strong>");
            });

            it("should render __text__ as bold", () => {
                const result = renderMarkdown("This is __bold__ text");
                expect(result).toContain("<strong>bold</strong>");
            });

            it("should handle multiple bold sections", () => {
                const result = renderMarkdown("**one** and **two**");
                expect(result).toContain("<strong>one</strong>");
                expect(result).toContain("<strong>two</strong>");
            });
        });

        describe("italic text", () => {
            it("should render *text* as italic", () => {
                const result = renderMarkdown("This is *italic* text");
                expect(result).toContain("<em>italic</em>");
            });

            it("should render _text_ as italic", () => {
                const result = renderMarkdown("This is _italic_ text");
                expect(result).toContain("<em>italic</em>");
            });

            it("should not render underscores inside words as italic", () => {
                const result = renderMarkdown("snake_case_variable");
                expect(result).not.toContain("<em>");
            });
        });

        describe("inline code", () => {
            it("should render `code` with code styling", () => {
                const result = renderMarkdown("Use `console.log()` for debugging");
                expect(result).toContain("<code");
                expect(result).toContain("console.log()");
                expect(result).toContain("</code>");
            });

            it("should escape HTML inside inline code", () => {
                const result = renderMarkdown("Use `<div>` element");
                expect(result).toContain("&lt;div&gt;");
            });

            it("should not process markdown inside inline code", () => {
                const result = renderMarkdown("Use `**not bold**` here");
                expect(result).toContain("**not bold**");
                expect(result).not.toContain("<strong>");
            });
        });

        describe("code blocks", () => {
            it("should render fenced code blocks", () => {
                const markdown = "```\nconst x = 1;\n```";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<pre");
                expect(result).toContain("<code");
                expect(result).toContain("const x = 1;");
                expect(result).toContain("</code>");
                expect(result).toContain("</pre>");
            });

            it("should include language class when specified", () => {
                const markdown = "```javascript\nconst x = 1;\n```";
                const result = renderMarkdown(markdown);
                expect(result).toContain('class="language-javascript"');
            });

            it("should escape HTML inside code blocks", () => {
                const markdown = "```\n<script>alert('xss')</script>\n```";
                const result = renderMarkdown(markdown);
                expect(result).toContain("&lt;script&gt;");
                expect(result).not.toContain("<script>");
            });

            it("should preserve whitespace in code blocks", () => {
                const markdown = "```\n  indented\n    more indented\n```";
                const result = renderMarkdown(markdown);
                expect(result).toContain("  indented");
                expect(result).toContain("    more indented");
            });

            it("should handle multiline code blocks", () => {
                const markdown = "```typescript\ninterface User {\n  name: string;\n  age: number;\n}\n```";
                const result = renderMarkdown(markdown);
                expect(result).toContain("interface User {");
                expect(result).toContain("name: string;");
            });
        });

        describe("headers", () => {
            it("should render h1 headers", () => {
                const result = renderMarkdown("# Header 1");
                expect(result).toContain("<h1");
                expect(result).toContain("Header 1");
                expect(result).toContain("</h1>");
            });

            it("should render h2 headers", () => {
                const result = renderMarkdown("## Header 2");
                expect(result).toContain("<h2");
                expect(result).toContain("Header 2");
            });

            it("should render h3-h6 headers", () => {
                expect(renderMarkdown("### Header")).toContain("<h3");
                expect(renderMarkdown("#### Header")).toContain("<h4");
                expect(renderMarkdown("##### Header")).toContain("<h5");
                expect(renderMarkdown("###### Header")).toContain("<h6");
            });

            it("should process inline markdown in headers", () => {
                const result = renderMarkdown("# **Bold** Header");
                expect(result).toContain("<h1");
                expect(result).toContain("<strong>Bold</strong>");
            });
        });

        describe("unordered lists", () => {
            it("should render dash lists", () => {
                const markdown = "- Item 1\n- Item 2\n- Item 3";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<ul");
                expect(result).toContain("<li>Item 1</li>");
                expect(result).toContain("<li>Item 2</li>");
                expect(result).toContain("<li>Item 3</li>");
                expect(result).toContain("</ul>");
            });

            it("should render asterisk lists", () => {
                const markdown = "* Item 1\n* Item 2";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<ul");
                expect(result).toContain("<li>Item 1</li>");
            });

            it("should render plus sign lists", () => {
                const markdown = "+ Item 1\n+ Item 2";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<ul");
                expect(result).toContain("<li>Item 1</li>");
            });

            it("should process inline markdown in list items", () => {
                const markdown = "- **Bold** item\n- *Italic* item";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<strong>Bold</strong>");
                expect(result).toContain("<em>Italic</em>");
            });
        });

        describe("ordered lists", () => {
            it("should render numbered lists", () => {
                const markdown = "1. First\n2. Second\n3. Third";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<ol");
                expect(result).toContain("<li>First</li>");
                expect(result).toContain("<li>Second</li>");
                expect(result).toContain("<li>Third</li>");
                expect(result).toContain("</ol>");
            });

            it("should process inline markdown in ordered list items", () => {
                const markdown = "1. **Bold** item\n2. `code` item";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<strong>Bold</strong>");
                expect(result).toContain("<code");
            });
        });

        describe("links", () => {
            it("should render markdown links", () => {
                const result = renderMarkdown("[Click here](https://example.com)");
                expect(result).toContain("<a");
                expect(result).toContain('href="https://example.com"');
                expect(result).toContain("Click here");
                expect(result).toContain("</a>");
            });

            it("should add target blank and noopener", () => {
                const result = renderMarkdown("[Link](https://example.com)");
                expect(result).toContain('target="_blank"');
                expect(result).toContain('rel="noopener noreferrer"');
            });

            it("should sanitize javascript: URLs", () => {
                const result = renderMarkdown("[Click](javascript:alert('xss'))");
                expect(result).toContain('href="#"');
                expect(result).not.toContain("javascript:");
            });

            it("should sanitize data: URLs", () => {
                const result = renderMarkdown("[Click](data:text/html,<script>alert('xss')</script>)");
                expect(result).toContain('href="#"');
                expect(result).not.toContain("data:");
            });
        });

        describe("blockquotes", () => {
            it("should render blockquotes", () => {
                const result = renderMarkdown("> This is a quote");
                expect(result).toContain("<blockquote");
                expect(result).toContain("This is a quote");
                expect(result).toContain("</blockquote>");
            });

            it("should handle multiline blockquotes", () => {
                const markdown = "> Line 1\n> Line 2";
                const result = renderMarkdown(markdown);
                expect(result).toContain("Line 1");
                expect(result).toContain("Line 2");
            });
        });

        describe("horizontal rules", () => {
            it("should render --- as horizontal rule", () => {
                const result = renderMarkdown("---");
                expect(result).toContain("<hr");
            });

            it("should render *** as horizontal rule", () => {
                const result = renderMarkdown("***");
                expect(result).toContain("<hr");
            });

            it("should render ___ as horizontal rule", () => {
                const result = renderMarkdown("___");
                expect(result).toContain("<hr");
            });
        });

        describe("paragraphs and line breaks", () => {
            it("should create separate paragraphs for text separated by blank lines", () => {
                const markdown = "Paragraph 1\n\nParagraph 2";
                const result = renderMarkdown(markdown);
                expect(result).toContain("Paragraph 1");
                expect(result).toContain("Paragraph 2");
                // Both should be in separate paragraph tags
                const pCount = (result.match(/<p/g) || []).length;
                expect(pCount).toBe(2);
            });

            it("should create line breaks for consecutive lines", () => {
                const markdown = "Line 1\nLine 2\nLine 3";
                const result = renderMarkdown(markdown);
                expect(result).toContain("Line 1<br>Line 2<br>Line 3");
            });
        });

        describe("complex markdown", () => {
            it("should handle combined formatting", () => {
                const markdown = "**bold and *italic* together**";
                const result = renderMarkdown(markdown);
                expect(result).toContain("<strong>");
                expect(result).toContain("<em>");
            });

            it("should handle mixed content", () => {
                const markdown = `# Title

This is a paragraph with **bold** and *italic*.

- List item 1
- List item 2

\`\`\`javascript
const x = 1;
\`\`\`

> A quote`;
                const result = renderMarkdown(markdown);
                expect(result).toContain("<h1");
                expect(result).toContain("<strong>bold</strong>");
                expect(result).toContain("<em>italic</em>");
                expect(result).toContain("<ul");
                expect(result).toContain("<pre");
                expect(result).toContain("<blockquote");
            });
        });

        describe("streaming mode", () => {
            it("should handle unclosed code blocks in streaming mode", () => {
                const markdown = "```javascript\nconst x = 1;";
                const result = renderMarkdown(markdown, { streaming: true });
                // Should still render as code block even without closing fence
                expect(result).toContain("<pre");
                expect(result).toContain("const x = 1;");
            });

            it("should handle partial markdown gracefully", () => {
                const markdown = "This is **bo";
                const result = renderMarkdown(markdown, { streaming: true });
                // Should not crash, render as-is
                expect(result).toContain("**bo");
            });
        });

        describe("XSS prevention", () => {
            it("should escape HTML in all contexts", () => {
                const malicious = `<img src="x" onerror="alert('xss')">`;
                const result = renderMarkdown(malicious);
                // The <img tag should be escaped, not rendered as an actual element
                expect(result).not.toContain("<img");
                expect(result).toContain("&lt;img");
            });

            it("should escape script tags", () => {
                const result = renderMarkdown("<script>alert('xss')</script>");
                expect(result).not.toContain("<script>");
            });

            it("should escape HTML in headers", () => {
                const result = renderMarkdown("# <script>alert('xss')</script>");
                expect(result).not.toContain("<script>");
            });

            it("should escape HTML in list items", () => {
                const result = renderMarkdown("- <img src=x onerror=alert('xss')>");
                expect(result).not.toContain("<img");
            });
        });
    });

    describe("containsMarkdown", () => {
        it("should detect bold markers", () => {
            expect(containsMarkdown("This is **bold**")).toBe(true);
            expect(containsMarkdown("This is __bold__")).toBe(true);
        });

        it("should detect italic markers", () => {
            expect(containsMarkdown("This is *italic*")).toBe(true);
            expect(containsMarkdown("This is _italic_")).toBe(true);
        });

        it("should detect code markers", () => {
            expect(containsMarkdown("Use `code` here")).toBe(true);
            expect(containsMarkdown("```\ncode block\n```")).toBe(true);
        });

        it("should detect headers", () => {
            expect(containsMarkdown("# Header")).toBe(true);
            expect(containsMarkdown("## Header")).toBe(true);
        });

        it("should detect lists", () => {
            expect(containsMarkdown("- item")).toBe(true);
            expect(containsMarkdown("* item")).toBe(true);
            expect(containsMarkdown("1. item")).toBe(true);
        });

        it("should detect links", () => {
            expect(containsMarkdown("[text](url)")).toBe(true);
        });

        it("should return false for plain text", () => {
            expect(containsMarkdown("This is plain text")).toBe(false);
            expect(containsMarkdown("Hello, world!")).toBe(false);
        });
    });
});
