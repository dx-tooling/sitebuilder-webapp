import { Controller } from "@hotwired/stimulus";

interface AgentEvent {
    kind: string;
    toolName?: string | null;
    toolInputs?: Array<{ key: string; value: string }> | null;
    toolResult?: string | null;
    errorMessage?: string | null;
}

interface PollChunk {
    id: number;
    chunkType: string;
    payload: string;
}

interface PollResponse {
    chunks: PollChunk[];
    lastId: number;
    status: string;
}

interface RunResponse {
    sessionId?: string;
    error?: string;
}

export default class extends Controller {
    static values = {
        runUrl: String,
        pollUrlTemplate: String,
        workspacePath: { type: String, default: "" },
    };

    static targets = ["messages", "instruction", "workspacePath", "submit", "autoScroll"];

    declare readonly runUrlValue: string;
    declare readonly pollUrlTemplateValue: string;
    declare readonly workspacePathValue: string;

    declare readonly hasMessagesTarget: boolean;
    declare readonly messagesTarget: HTMLElement;
    declare readonly hasInstructionTarget: boolean;
    declare readonly instructionTarget: HTMLTextAreaElement;
    declare readonly hasWorkspacePathTarget: boolean;
    declare readonly workspacePathTarget: HTMLInputElement;
    declare readonly hasSubmitTarget: boolean;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly hasAutoScrollTarget: boolean;
    declare readonly autoScrollTarget: HTMLInputElement;

    private pollingIntervalId: ReturnType<typeof setInterval> | null = null;
    private autoScrollEnabled: boolean = true;

    disconnect(): void {
        this.stopPolling();
    }

    toggleAutoScroll(): void {
        this.autoScrollEnabled = this.hasAutoScrollTarget ? this.autoScrollTarget.checked : true;
    }

    private scrollToBottom(): void {
        if (this.autoScrollEnabled && this.hasMessagesTarget) {
            this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        }
    }

    async handleSubmit(event: Event): Promise<void> {
        event.preventDefault();

        if (!this.hasInstructionTarget || !this.hasMessagesTarget || !this.hasSubmitTarget) {
            return;
        }

        const instruction = this.instructionTarget.value.trim();
        if (!instruction) {
            return;
        }

        const workspacePath = this.hasWorkspacePathTarget
            ? this.workspacePathTarget.value.trim()
            : this.workspacePathValue;
        const runUrl = this.runUrlValue;

        // Remove the default placeholder on first message
        const placeholder = this.messagesTarget.querySelector("p.text-dark-500");
        if (placeholder) {
            placeholder.remove();
        }

        // Append user message
        const userEl = document.createElement("div");
        userEl.className = "flex justify-end";
        userEl.innerHTML = `<div class="max-w-[85%] rounded-lg px-4 py-2 bg-primary-100 dark:bg-primary-900/30 text-dark-900 dark:text-dark-100 text-sm">${escapeHtml(instruction)}</div>`;
        this.messagesTarget.appendChild(userEl);
        this.scrollToBottom();

        // Assistant placeholder (events + text will be polled here)
        const assistantEl = document.createElement("div");
        assistantEl.className = "flex justify-start flex-col gap-2";
        assistantEl.dataset.assistantTurn = "1";
        const inner = document.createElement("div");
        inner.className =
            "max-w-[85%] rounded-lg px-4 py-2 bg-dark-100 dark:bg-dark-700/50 text-dark-800 dark:text-dark-200 text-sm space-y-2";
        assistantEl.appendChild(inner);
        this.messagesTarget.appendChild(assistantEl);
        this.scrollToBottom();

        this.submitTarget.disabled = true;
        this.submitTarget.textContent = "Working…";

        const form = (event.target as HTMLElement).closest("form");
        const csrfInput = form?.querySelector('input[name="_csrf_token"]') as HTMLInputElement | null;

        const formData = new FormData();
        formData.append("instruction", instruction);
        formData.append("workspace_path", workspacePath);
        if (csrfInput) {
            formData.append("_csrf_token", csrfInput.value);
        }

        try {
            const response = await fetch(runUrl, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData,
            });

            const data = (await response.json()) as RunResponse;

            if (!response.ok || !data.sessionId) {
                const msg = data.error || `Request failed: ${response.status}`;
                this.appendError(inner, msg);
                this.resetSubmitButton();
                return;
            }

            // Start polling for chunks
            this.startPolling(data.sessionId, inner);
        } catch (err) {
            const msg = err instanceof Error ? err.message : "Network error.";
            this.appendError(inner, msg);
            this.resetSubmitButton();
        }
    }

    private startPolling(sessionId: string, container: HTMLElement): void {
        let lastId = 0;
        const pollUrl = this.pollUrlTemplateValue.replace("__SESSION_ID__", sessionId);

        const poll = async (): Promise<void> => {
            try {
                const response = await fetch(`${pollUrl}?after=${lastId}`, {
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });

                if (!response.ok) {
                    this.appendError(container, `Poll failed: ${response.status}`);
                    this.stopPolling();
                    this.resetSubmitButton();
                    return;
                }

                const data = (await response.json()) as PollResponse;

                for (const chunk of data.chunks) {
                    if (this.handleChunk(chunk, container)) {
                        this.stopPolling();
                        this.resetSubmitButton();
                        return;
                    }
                }

                lastId = data.lastId;

                // Stop polling if session completed or failed
                if (data.status === "completed" || data.status === "failed") {
                    this.stopPolling();
                    this.resetSubmitButton();
                }
            } catch (err) {
                const msg = err instanceof Error ? err.message : "Polling error.";
                this.appendError(container, msg);
                this.stopPolling();
                this.resetSubmitButton();
            }
        };

        // Initial poll immediately, then every 500ms
        poll();
        this.pollingIntervalId = setInterval(poll, 500);
    }

    private stopPolling(): void {
        if (this.pollingIntervalId !== null) {
            clearInterval(this.pollingIntervalId);
            this.pollingIntervalId = null;
        }
    }

    private resetSubmitButton(): void {
        this.submitTarget.disabled = false;
        this.submitTarget.textContent = "Run";
    }

    /** Returns true when chunkType is done (caller should stop polling). */
    private handleChunk(chunk: PollChunk, container: HTMLElement): boolean {
        const payload = JSON.parse(chunk.payload) as {
            content?: string;
            kind?: string;
            toolName?: string;
            toolInputs?: Array<{ key: string; value: string }>;
            toolResult?: string;
            errorMessage?: string;
            success?: boolean;
        };

        if (chunk.chunkType === "text" && payload.content) {
            // Accumulate text into the current text element, or create one if needed
            const textEl = this.getOrCreateTextElement(container);
            textEl.textContent += payload.content;
            this.scrollToBottom();
        } else if (chunk.chunkType === "event") {
            const event: AgentEvent = {
                kind: payload.kind ?? "unknown",
                toolName: payload.toolName,
                toolInputs: payload.toolInputs,
                toolResult: payload.toolResult,
                errorMessage: payload.errorMessage,
            };
            container.appendChild(this.renderEvent(event));
            this.scrollToBottom();
        } else if (chunk.chunkType === "done") {
            if (payload.success === false && payload.errorMessage) {
                this.appendError(container, payload.errorMessage);
            }
            this.scrollToBottom();
            return true;
        }
        return false;
    }

    /**
     * Gets the current text element (last child with data-text-stream attribute),
     * or creates a new one if the last child is not a text element.
     */
    private getOrCreateTextElement(container: HTMLElement): HTMLElement {
        const lastChild = container.lastElementChild;
        if (lastChild instanceof HTMLElement && lastChild.dataset.textStream === "1") {
            return lastChild;
        }

        const textEl = document.createElement("div");
        textEl.className = "whitespace-pre-wrap";
        textEl.dataset.textStream = "1";
        container.appendChild(textEl);

        return textEl;
    }

    private renderEvent(e: AgentEvent): HTMLElement {
        const wrap = document.createElement("div");
        wrap.className = "text-xs font-mono";

        switch (e.kind) {
            case "inference_start":
                wrap.textContent = "→ Sending to LLM…";
                wrap.classList.add("text-amber-600", "dark:text-amber-400");
                break;
            case "inference_stop":
                wrap.textContent = "← LLM response received";
                wrap.classList.add("text-amber-600", "dark:text-amber-400");
                break;
            case "tool_calling":
                wrap.innerHTML = `▶ Calling: <span class="font-semibold">${escapeHtml(e.toolName ?? "?")}</span>`;
                if (e.toolInputs && e.toolInputs.length > 0) {
                    const ul = document.createElement("ul");
                    ul.className = "mt-1 ml-2 list-disc";
                    for (const t of e.toolInputs) {
                        const li = document.createElement("li");
                        li.textContent = `${t.key}: ${t.value}`;
                        ul.appendChild(li);
                    }
                    wrap.appendChild(ul);
                }
                wrap.classList.add("text-blue-700", "dark:text-blue-300");
                break;
            case "tool_called":
                wrap.innerHTML = `◀ Result: ${escapeHtml((e.toolResult ?? "").slice(0, 200))}${(e.toolResult?.length ?? 0) > 200 ? "…" : ""}`;
                wrap.classList.add("text-green-700", "dark:text-green-300");
                break;
            case "agent_error":
                wrap.textContent = `✖ ${e.errorMessage ?? "Unknown error"}`;
                wrap.classList.add("text-red-600", "dark:text-red-400");
                break;
            default:
                wrap.textContent = `[${e.kind}]`;
        }

        return wrap;
    }

    private appendError(container: HTMLElement, message: string): void {
        const div = document.createElement("div");
        div.className = "text-red-600 dark:text-red-400 text-sm";
        div.textContent = `✖ ${message}`;
        container.appendChild(div);
        this.scrollToBottom();
    }
}

function escapeHtml(s: string): string {
    const div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
}
