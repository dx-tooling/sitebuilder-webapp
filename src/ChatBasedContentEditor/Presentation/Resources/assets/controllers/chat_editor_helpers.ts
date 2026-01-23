/**
 * Shared types and utility functions for the chat-based content editor.
 * Extracted for testability.
 */

export interface AgentEvent {
    kind: string;
    toolName?: string | null;
    toolInputs?: Array<{ key: string; value: string }> | null;
    toolResult?: string | null;
    errorMessage?: string | null;
}

export interface PollChunk {
    id: number;
    chunkType: string;
    payload: string;
}

export interface PollResponse {
    chunks: PollChunk[];
    lastId: number;
    status: string;
    contextUsage?: ContextUsageData;
}

export interface ContextUsageData {
    usedTokens: number;
    maxTokens: number;
    modelName: string;
    inputTokens: number;
    outputTokens: number;
    inputCost: number;
    outputCost: number;
    totalCost: number;
}

export interface RunResponse {
    sessionId?: string;
    error?: string;
}

export interface ActiveSessionData {
    id: string;
    status: string;
    instruction: string;
    chunks: PollChunk[];
    lastChunkId: number;
}

export interface TurnData {
    instruction: string;
    response: string;
    status: string;
    events: PollChunk[];
}

export interface ChunkPayload {
    content?: string;
    kind?: string;
    toolName?: string;
    toolInputs?: Array<{ key: string; value: string }>;
    toolResult?: string;
    errorMessage?: string;
    success?: boolean;
}

/**
 * Escape HTML special characters to prevent XSS.
 */
export function escapeHtml(s: string): string {
    const div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
}

/**
 * Format a number with thousand separators.
 */
export function formatInt(n: number): string {
    return n.toLocaleString("en-US", { maximumFractionDigits: 0 });
}

/**
 * Parse a chunk payload JSON string into a typed object.
 */
export function parseChunkPayload(payloadJson: string): ChunkPayload {
    return JSON.parse(payloadJson) as ChunkPayload;
}

/**
 * Convert a chunk payload to an AgentEvent for rendering.
 */
export function payloadToAgentEvent(payload: ChunkPayload): AgentEvent {
    return {
        kind: payload.kind ?? "unknown",
        toolName: payload.toolName,
        toolInputs: payload.toolInputs,
        toolResult: payload.toolResult,
        errorMessage: payload.errorMessage,
    };
}

/**
 * Determine if a chunk represents session completion.
 */
export function isSessionComplete(chunk: PollChunk): boolean {
    return chunk.chunkType === "done";
}

/**
 * Determine if a session status indicates work is in progress.
 */
export function isActiveSessionStatus(status: string): boolean {
    return status === "pending" || status === "running";
}

/**
 * Determine if a session completed successfully.
 */
export function isSuccessStatus(status: string): boolean {
    return status === "completed";
}

/**
 * Truncate a string to a maximum length, adding ellipsis if needed.
 */
export function truncateString(s: string, maxLength: number): string {
    if (s.length <= maxLength) {
        return s;
    }
    return s.slice(0, maxLength) + "…";
}
