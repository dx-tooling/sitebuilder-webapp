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
    getCompletedContainerStyle,
    getWorkingContainerStyle,
    getProgressAnimationState,
    shouldShowEventFeedback,
    type PollChunk,
    type ChunkPayload,
} from "../../../../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_helpers.ts";

describe("chat_editor_helpers", () => {
    describe("escapeHtml", () => {
        it("should escape HTML special characters", () => {
            expect(escapeHtml("<script>alert('xss')</script>")).toBe("&lt;script&gt;alert('xss')&lt;/script&gt;");
        });

        it("should escape ampersands", () => {
            expect(escapeHtml("foo & bar")).toBe("foo &amp; bar");
        });

        it("should escape quotes", () => {
            expect(escapeHtml('say "hello"')).toBe('say "hello"');
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

    describe("getCompletedContainerStyle", () => {
        it("should return Done label text", () => {
            const style = getCompletedContainerStyle();
            expect(style.labelText).toBe("All set");
        });

        it("should return checkmark emoji", () => {
            const style = getCompletedContainerStyle();
            expect(style.sparkleEmoji).toBe("✅");
        });

        it("should return green indicator classes", () => {
            const style = getCompletedContainerStyle();
            expect(style.indicatorClass).toContain("green");
        });

        it("should return green header background classes", () => {
            const style = getCompletedContainerStyle();
            expect(style.headerBgClass).toContain("green");
            expect(style.headerBgClass).toContain("emerald");
        });

        it("should return green label color classes", () => {
            const style = getCompletedContainerStyle();
            expect(style.labelColorClass).toContain("green");
        });

        it("should not contain any red or error styling", () => {
            const style = getCompletedContainerStyle();
            expect(style.labelText).not.toContain("Failed");
            expect(style.labelText).not.toContain("Error");
            expect(style.indicatorClass).not.toContain("red");
            expect(style.headerBgClass).not.toContain("red");
        });
    });

    describe("getWorkingContainerStyle", () => {
        it("should return Working... label text", () => {
            const style = getWorkingContainerStyle();
            expect(style.labelText).toBe("In progress");
        });

        it("should return sparkle emoji", () => {
            const style = getWorkingContainerStyle();
            expect(style.sparkleEmoji).toBe("✨");
        });

        it("should return purple/blue indicator classes", () => {
            const style = getWorkingContainerStyle();
            expect(style.indicatorClass).toContain("purple");
            expect(style.indicatorClass).toContain("blue");
        });

        it("should return purple/blue header background classes", () => {
            const style = getWorkingContainerStyle();
            expect(style.headerBgClass).toContain("purple");
            expect(style.headerBgClass).toContain("blue");
        });
    });

    describe("getProgressAnimationState", () => {
        it("should intensify for tool_calling events", () => {
            const state = getProgressAnimationState("tool_calling");
            expect(state.intensify).toBe(true);
            expect(state.returnToNormal).toBe(false);
        });

        it("should intensify for inference_start events", () => {
            const state = getProgressAnimationState("inference_start");
            expect(state.intensify).toBe(true);
            expect(state.returnToNormal).toBe(false);
        });

        it("should return to normal for tool_called events", () => {
            const state = getProgressAnimationState("tool_called");
            expect(state.intensify).toBe(false);
            expect(state.returnToNormal).toBe(true);
        });

        it("should return to normal for inference_stop events", () => {
            const state = getProgressAnimationState("inference_stop");
            expect(state.intensify).toBe(false);
            expect(state.returnToNormal).toBe(true);
        });

        it("should not change animation for agent_error events", () => {
            const state = getProgressAnimationState("agent_error");
            expect(state.intensify).toBe(false);
            expect(state.returnToNormal).toBe(false);
        });

        it("should not change animation for unknown events", () => {
            const state = getProgressAnimationState("some_other_event");
            expect(state.intensify).toBe(false);
            expect(state.returnToNormal).toBe(false);
        });
    });

    describe("shouldShowEventFeedback", () => {
        it("should show feedback for tool_calling events", () => {
            expect(shouldShowEventFeedback("tool_calling")).toBe(true);
        });

        it("should show feedback for tool_called events", () => {
            expect(shouldShowEventFeedback("tool_called")).toBe(true);
        });

        it("should show feedback for inference_start events", () => {
            expect(shouldShowEventFeedback("inference_start")).toBe(true);
        });

        it("should show feedback for inference_stop events", () => {
            expect(shouldShowEventFeedback("inference_stop")).toBe(true);
        });

        it("should NOT show feedback for agent_error events", () => {
            expect(shouldShowEventFeedback("agent_error")).toBe(false);
        });

        it("should NOT show feedback for unknown events", () => {
            expect(shouldShowEventFeedback("some_random_event")).toBe(false);
        });
    });
});
