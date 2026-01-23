import { describe, it, expect } from "vitest";
import {
    escapeHtml,
    formatInt,
    parseChunkPayload,
    payloadToAgentEvent,
    isSessionComplete,
    isActiveSessionStatus,
    isSuccessStatus,
    truncateString,
    type PollChunk,
    type ChunkPayload,
} from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_helpers.ts";

describe("chat_editor_helpers", () => {
    describe("escapeHtml", () => {
        it("should escape HTML special characters", () => {
            expect(escapeHtml("<script>alert('xss')</script>")).toBe(
                "&lt;script&gt;alert('xss')&lt;/script&gt;",
            );
        });

        it("should escape ampersands", () => {
            expect(escapeHtml("foo & bar")).toBe("foo &amp; bar");
        });

        it("should escape quotes", () => {
            expect(escapeHtml('say "hello"')).toBe("say \"hello\"");
        });

        it("should handle empty string", () => {
            expect(escapeHtml("")).toBe("");
        });

        it("should preserve normal text", () => {
            expect(escapeHtml("Hello, world!")).toBe("Hello, world!");
        });
    });

    describe("formatInt", () => {
        it("should format small numbers without separators", () => {
            expect(formatInt(123)).toBe("123");
        });

        it("should add thousand separators", () => {
            expect(formatInt(1234)).toBe("1,234");
            expect(formatInt(1234567)).toBe("1,234,567");
        });

        it("should handle zero", () => {
            expect(formatInt(0)).toBe("0");
        });

        it("should truncate decimals", () => {
            expect(formatInt(1234.567)).toBe("1,235");
        });
    });

    describe("parseChunkPayload", () => {
        it("should parse text chunk payload", () => {
            const json = JSON.stringify({ content: "Hello, world!" });
            const result = parseChunkPayload(json);
            expect(result.content).toBe("Hello, world!");
        });

        it("should parse event chunk payload", () => {
            const json = JSON.stringify({
                kind: "tool_calling",
                toolName: "ReadFile",
                toolInputs: [{ key: "path", value: "/test.txt" }],
            });
            const result = parseChunkPayload(json);
            expect(result.kind).toBe("tool_calling");
            expect(result.toolName).toBe("ReadFile");
            expect(result.toolInputs).toHaveLength(1);
        });

        it("should parse done chunk payload", () => {
            const json = JSON.stringify({ success: true });
            const result = parseChunkPayload(json);
            expect(result.success).toBe(true);
        });

        it("should parse error payload", () => {
            const json = JSON.stringify({
                success: false,
                errorMessage: "Something went wrong",
            });
            const result = parseChunkPayload(json);
            expect(result.success).toBe(false);
            expect(result.errorMessage).toBe("Something went wrong");
        });
    });

    describe("payloadToAgentEvent", () => {
        it("should convert payload to agent event", () => {
            const payload: ChunkPayload = {
                kind: "tool_calling",
                toolName: "WriteFile",
                toolInputs: [{ key: "content", value: "test" }],
            };
            const event = payloadToAgentEvent(payload);
            expect(event.kind).toBe("tool_calling");
            expect(event.toolName).toBe("WriteFile");
            expect(event.toolInputs).toEqual([{ key: "content", value: "test" }]);
        });

        it("should default kind to unknown", () => {
            const payload: ChunkPayload = {};
            const event = payloadToAgentEvent(payload);
            expect(event.kind).toBe("unknown");
        });

        it("should preserve null values", () => {
            const payload: ChunkPayload = {
                kind: "tool_called",
                toolResult: null as unknown as string,
            };
            const event = payloadToAgentEvent(payload);
            expect(event.toolResult).toBeNull();
        });
    });

    describe("isSessionComplete", () => {
        it("should return true for done chunks", () => {
            const chunk: PollChunk = {
                id: 1,
                chunkType: "done",
                payload: "{}",
            };
            expect(isSessionComplete(chunk)).toBe(true);
        });

        it("should return false for text chunks", () => {
            const chunk: PollChunk = {
                id: 1,
                chunkType: "text",
                payload: '{"content":"hello"}',
            };
            expect(isSessionComplete(chunk)).toBe(false);
        });

        it("should return false for event chunks", () => {
            const chunk: PollChunk = {
                id: 1,
                chunkType: "event",
                payload: '{"kind":"tool_calling"}',
            };
            expect(isSessionComplete(chunk)).toBe(false);
        });
    });

    describe("isActiveSessionStatus", () => {
        it("should return true for pending status", () => {
            expect(isActiveSessionStatus("pending")).toBe(true);
        });

        it("should return true for running status", () => {
            expect(isActiveSessionStatus("running")).toBe(true);
        });

        it("should return false for completed status", () => {
            expect(isActiveSessionStatus("completed")).toBe(false);
        });

        it("should return false for failed status", () => {
            expect(isActiveSessionStatus("failed")).toBe(false);
        });
    });

    describe("isSuccessStatus", () => {
        it("should return true for completed status", () => {
            expect(isSuccessStatus("completed")).toBe(true);
        });

        it("should return false for failed status", () => {
            expect(isSuccessStatus("failed")).toBe(false);
        });

        it("should return false for pending status", () => {
            expect(isSuccessStatus("pending")).toBe(false);
        });
    });

    describe("truncateString", () => {
        it("should not truncate short strings", () => {
            expect(truncateString("hello", 10)).toBe("hello");
        });

        it("should truncate long strings with ellipsis", () => {
            expect(truncateString("hello world", 5)).toBe("hello…");
        });

        it("should handle exact length strings", () => {
            expect(truncateString("hello", 5)).toBe("hello");
        });

        it("should handle empty strings", () => {
            expect(truncateString("", 5)).toBe("");
        });
    });
});
