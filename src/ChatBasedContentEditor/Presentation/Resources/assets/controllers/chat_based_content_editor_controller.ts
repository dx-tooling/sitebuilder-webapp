import { Controller } from "@hotwired/stimulus";

interface AgentEvent {
    kind: string;
    toolName?: string | null;
    toolInputs?: Array<{ key: string; value: string }> | null;
    toolResult?: string | null;
    errorMessage?: string | null;
}

interface StreamChunk {
    chunkType: "text" | "event" | "done";
    content?: string;
    event?: AgentEvent;
    success?: boolean;
    errorMessage?: string | null;
}

export default class extends Controller {
    static values = {
        runUrl: String,
        workspacePath: { type: String, default: "" },
    };

    static targets = ["messages", "instruction", "workspacePath", "submit"];

    declare readonly runUrlValue: string;
    declare readonly workspacePathValue: string;

    declare readonly hasMessagesTarget: boolean;
    declare readonly messagesTarget: HTMLElement;
    declare readonly hasInstructionTarget: boolean;
    declare readonly instructionTarget: HTMLTextAreaElement;
    declare readonly hasWorkspacePathTarget: boolean;
    declare readonly workspacePathTarget: HTMLInputElement;
    declare readonly hasSubmitTarget: boolean;
    declare readonly submitTarget: HTMLButtonElement;

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

        // Assistant placeholder (events + text will be streamed here)
        const assistantEl = document.createElement("div");
        assistantEl.className = "flex justify-start flex-col gap-2";
        assistantEl.dataset.assistantTurn = "1";
        const inner = document.createElement("div");
        inner.className =
            "max-w-[85%] rounded-lg px-4 py-2 bg-dark-100 dark:bg-dark-700/50 text-dark-800 dark:text-dark-200 text-sm space-y-2";
        assistantEl.appendChild(inner);
        this.messagesTarget.appendChild(assistantEl);

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

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const msg = (data as { error?: string }).error || `Request failed: ${response.status}`;
                this.appendError(inner, msg);
                return;
            }

            const reader = response.body?.getReader();
            if (!reader) {
                this.appendError(inner, "No response body.");
                return;
            }

            const decoder = new TextDecoder();
            let buffer = "";
            let seenDone = false;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split("\n");
                buffer = lines.pop() ?? "";
                for (const line of lines) {
                    if (!line.trim()) continue;
                    try {
                        const chunk = JSON.parse(line) as StreamChunk;
                        if (this.handleChunk(chunk, inner)) {
                            seenDone = true;
                            break;
                        }
                    } catch {
                        // skip malformed lines
                    }
                }
                if (seenDone) break;
            }
            // process remaining buffer
            if (buffer.trim()) {
                try {
                    const chunk = JSON.parse(buffer) as StreamChunk;
                    this.handleChunk(chunk, inner);
                } catch {
                    // skip
                }
            }
        } catch (err) {
            const msg = err instanceof Error ? err.message : "Network or stream error.";
            this.appendError(inner, msg);
        } finally {
            this.submitTarget.disabled = false;
            this.submitTarget.textContent = "Run";
        }
    }

    /** Returns true when chunkType is done (caller should stop reading). */
    private handleChunk(chunk: StreamChunk, container: HTMLElement): boolean {
        if (chunk.chunkType === "text" && chunk.content) {
            const wrap = document.createElement("div");
            wrap.className = "whitespace-pre-wrap";
            wrap.textContent = chunk.content;
            container.appendChild(wrap);
        } else if (chunk.chunkType === "event" && chunk.event) {
            container.appendChild(this.renderEvent(chunk.event));
        } else if (chunk.chunkType === "done") {
            if (chunk.success === false && chunk.errorMessage) {
                this.appendError(container, chunk.errorMessage);
            }
            return true;
        }
        return false;
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
    }
}

function escapeHtml(s: string): string {
    const div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
}
