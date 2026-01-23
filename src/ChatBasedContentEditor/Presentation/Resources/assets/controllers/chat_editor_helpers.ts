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

/**
 * Configuration for technical container styling.
 */
export interface TechnicalContainerStyle {
    headerBgClass: string;
    indicatorClass: string;
    labelText: string;
    labelColorClass: string;
    sparkleEmoji: string;
    countColorClass: string;
    chevronColorClass: string;
    messagesListBorderClass: string;
}

/**
 * Get the styling configuration for a completed technical container.
 * Always returns "Done" state styling since the agent handles errors gracefully.
 */
export function getCompletedContainerStyle(): TechnicalContainerStyle {
    return {
        headerBgClass:
            "from-green-50/80 to-emerald-50/80 dark:from-green-900/20 dark:to-emerald-900/20 border-green-200/50 dark:border-green-700/30",
        indicatorClass: "bg-green-500 dark:bg-green-400",
        labelText: "Done",
        labelColorClass: "from-green-600 to-green-600 dark:from-green-400 dark:to-green-400",
        sparkleEmoji: "✅",
        countColorClass: "text-green-500 dark:text-green-400 bg-green-100/50 dark:bg-green-900/30",
        chevronColorClass: "text-green-400 dark:text-green-500",
        messagesListBorderClass: "border-green-200/30 dark:border-green-700/20",
    };
}

/**
 * Get the styling configuration for a working/in-progress technical container.
 */
export function getWorkingContainerStyle(): TechnicalContainerStyle {
    return {
        headerBgClass:
            "from-purple-50/80 to-blue-50/80 dark:from-purple-900/20 dark:to-blue-900/20 border-purple-200/50 dark:border-purple-700/30",
        indicatorClass: "bg-gradient-to-r from-purple-500 to-blue-500 dark:from-purple-400 dark:to-blue-400",
        labelText: "Working...",
        labelColorClass: "from-purple-600 to-blue-600 dark:from-purple-400 dark:to-blue-400",
        sparkleEmoji: "✨",
        countColorClass: "text-purple-500 dark:text-purple-400 bg-purple-100/50 dark:bg-purple-900/30",
        chevronColorClass: "text-purple-400 dark:text-purple-500",
        messagesListBorderClass: "border-purple-200/30 dark:border-purple-700/20",
    };
}

/**
 * Configuration for progress indicator animation state.
 */
export interface ProgressAnimationState {
    intensify: boolean;
    returnToNormal: boolean;
}

/**
 * Determine the animation state change based on an agent event.
 * Returns whether to intensify animations, return to normal, or do nothing.
 */
export function getProgressAnimationState(eventKind: string): ProgressAnimationState {
    const intensifyEvents = ["tool_calling", "inference_start"];
    const normalizeEvents = ["tool_called", "inference_stop"];

    return {
        intensify: intensifyEvents.includes(eventKind),
        returnToNormal: normalizeEvents.includes(eventKind),
    };
}

/**
 * Determine if an event kind should trigger any visual feedback.
 * Note: agent_error events are intentionally not surfaced to avoid alarming users.
 */
export function shouldShowEventFeedback(eventKind: string): boolean {
    const feedbackEvents = ["tool_calling", "inference_start", "tool_called", "inference_stop"];
    return feedbackEvents.includes(eventKind);
}
