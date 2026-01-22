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
        conversationId: String,
    };

    static targets = ["messages", "instruction", "submit", "autoScroll", "submitOnEnter"];

    declare readonly runUrlValue: string;
    declare readonly pollUrlTemplateValue: string;
    declare readonly conversationIdValue: string;

    declare readonly hasMessagesTarget: boolean;
    declare readonly messagesTarget: HTMLElement;
    declare readonly hasInstructionTarget: boolean;
    declare readonly instructionTarget: HTMLTextAreaElement;
    declare readonly hasSubmitTarget: boolean;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly hasAutoScrollTarget: boolean;
    declare readonly autoScrollTarget: HTMLInputElement;
    declare readonly hasSubmitOnEnterTarget: boolean;
    declare readonly submitOnEnterTarget: HTMLInputElement;

    private pollingIntervalId: ReturnType<typeof setInterval> | null = null;
    private autoScrollEnabled: boolean = true;
    private submitOnEnterEnabled: boolean = true;

    disconnect(): void {
        this.stopPolling();
    }

    toggleAutoScroll(): void {
        this.autoScrollEnabled = this.hasAutoScrollTarget ? this.autoScrollTarget.checked : true;
    }

    toggleSubmitOnEnter(): void {
        this.submitOnEnterEnabled = this.hasSubmitOnEnterTarget ? this.submitOnEnterTarget.checked : true;
    }

    handleKeydown(event: KeyboardEvent): void {
        if (event.key === "Enter" && !event.shiftKey && this.submitOnEnterEnabled) {
            event.preventDefault();
            if (this.hasSubmitTarget && this.submitTarget.disabled) {
                return;
            }
            const form = this.instructionTarget.closest("form");
            if (form) {
                form.requestSubmit();
            }
        }
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

        const placeholder = this.messagesTarget.querySelector("p.text-dark-500");
        if (placeholder) {
            placeholder.remove();
        }

        const userEl = document.createElement("div");
        userEl.className = "flex justify-end";
        userEl.innerHTML = `<div class="max-w-[85%] rounded-lg px-4 py-2 bg-primary-100 dark:bg-primary-900/30 text-dark-900 dark:text-dark-100 text-sm">${escapeHtml(instruction)}</div>`;
        this.messagesTarget.appendChild(userEl);
        this.scrollToBottom();

        this.instructionTarget.value = "";

        const assistantEl = document.createElement("div");
        assistantEl.className = "flex justify-start flex-col gap-2";
        const inner = document.createElement("div");
        inner.className =
            "max-w-[85%] rounded-lg px-4 py-2 bg-dark-100 dark:bg-dark-700/50 text-dark-800 dark:text-dark-200 text-sm space-y-2";
        assistantEl.appendChild(inner);

        // Create technical messages container for this assistant response
        const technicalContainer = this.createTechnicalMessagesContainer();
        inner.appendChild(technicalContainer);

        this.messagesTarget.appendChild(assistantEl);
        this.scrollToBottom();

        this.submitTarget.disabled = true;
        this.submitTarget.textContent = "Working…";

        const form = (event.target as HTMLElement).closest("form");
        const csrfInput = form?.querySelector('input[name="_csrf_token"]') as HTMLInputElement | null;

        const formData = new FormData();
        formData.append("instruction", instruction);
        formData.append("conversation_id", this.conversationIdValue);
        if (csrfInput) {
            formData.append("_csrf_token", csrfInput.value);
        }

        try {
            const response = await fetch(this.runUrlValue, {
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
            this.appendTechnicalEvent(container, event);
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

    private createTechnicalMessagesContainer(): HTMLElement {
        const container = document.createElement("div");
        container.className = "technical-messages-container";
        container.dataset.technicalMessages = "1";

        const header = document.createElement("button");
        header.type = "button";
        header.className =
            "flex items-center gap-2 w-full text-left py-1.5 px-2 rounded hover:bg-dark-50 dark:hover:bg-dark-800/50 transition-colors";
        header.dataset.header = "1";
        header.addEventListener("click", () => {
            this.toggleTechnicalMessages(container);
        });

        const indicator = document.createElement("div");
        indicator.className =
            "technical-indicator w-2 h-2 rounded-full bg-blue-400/50 dark:bg-blue-500/50 flex-shrink-0";
        indicator.dataset.indicator = "1";

        const label = document.createElement("span");
        label.className = "text-[11px] text-dark-600 dark:text-dark-300 font-medium";
        label.textContent = "Working...";
        label.dataset.label = "1";

        const count = document.createElement("span");
        count.className = "text-[10px] text-dark-400 dark:text-dark-500 ml-auto";
        count.dataset.count = "1";
        count.textContent = "0";

        const chevron = document.createElement("svg");
        chevron.className = "w-3 h-3 text-dark-400 dark:text-dark-500 transition-transform";
        chevron.dataset.chevron = "1";
        chevron.innerHTML = '<path fill="currentColor" d="M6 9l6 6 6-6H6z"/>';
        chevron.setAttribute("viewBox", "0 0 24 24");

        header.appendChild(indicator);
        header.appendChild(label);
        header.appendChild(count);
        header.appendChild(chevron);

        const messagesList = document.createElement("div");
        messagesList.className = "technical-messages-list max-h-[120px] overflow-y-auto space-y-1 px-2 py-1 hidden";
        messagesList.dataset.messagesList = "1";

        container.appendChild(header);
        container.appendChild(messagesList);

        return container;
    }

    private getTechnicalMessagesContainer(container: HTMLElement): HTMLElement | null {
        return container.querySelector<HTMLElement>('[data-technical-messages="1"]');
    }

    private appendTechnicalEvent(container: HTMLElement, event: AgentEvent): void {
        const technicalContainer = this.getTechnicalMessagesContainer(container);
        if (!technicalContainer) {
            return;
        }

        const messagesList = technicalContainer.querySelector<HTMLElement>('[data-messages-list="1"]');
        const countEl = technicalContainer.querySelector<HTMLElement>('[data-count="1"]');

        if (!messagesList || !countEl) {
            return;
        }

        const eventEl = this.renderTechnicalEvent(event);
        messagesList.appendChild(eventEl);

        const currentCount = parseInt(countEl.textContent || "0", 10);
        countEl.textContent = String(currentCount + 1);

        // Auto-scroll within the technical messages list if expanded
        if (!messagesList.classList.contains("hidden")) {
            messagesList.scrollTop = messagesList.scrollHeight;
        }

        // Update pulsing indicator based on activity
        this.updateTechnicalIndicator(technicalContainer, event);
    }

    private renderTechnicalEvent(e: AgentEvent): HTMLElement {
        const wrap = document.createElement("div");
        wrap.className = "text-[10px] font-mono leading-relaxed";

        switch (e.kind) {
            case "inference_start":
                wrap.textContent = "→ Sending to LLM…";
                wrap.classList.add("text-amber-600/70", "dark:text-amber-400/70");
                break;
            case "inference_stop":
                wrap.textContent = "← LLM response received";
                wrap.classList.add("text-amber-600/70", "dark:text-amber-400/70");
                break;
            case "tool_calling":
                wrap.innerHTML = `▶ <span class="font-medium">${escapeHtml(e.toolName ?? "?")}</span>`;
                if (e.toolInputs && e.toolInputs.length > 0) {
                    const ul = document.createElement("ul");
                    ul.className = "mt-0.5 ml-3 list-disc space-y-0.5";
                    for (const t of e.toolInputs) {
                        const li = document.createElement("li");
                        li.className = "text-[9px]";
                        const displayValue = t.value.length > 50 ? t.value.slice(0, 50) + "…" : t.value;
                        li.textContent = `${t.key}: ${displayValue}`;
                        ul.appendChild(li);
                    }
                    wrap.appendChild(ul);
                }
                wrap.classList.add("text-blue-600/70", "dark:text-blue-400/70");
                break;
            case "tool_called": {
                const result = (e.toolResult ?? "").slice(0, 100);
                wrap.innerHTML = `◀ ${escapeHtml(result)}${(e.toolResult?.length ?? 0) > 100 ? "…" : ""}`;
                wrap.classList.add("text-green-600/70", "dark:text-green-400/70");
                break;
            }
            case "agent_error":
                wrap.textContent = `✖ ${e.errorMessage ?? "Unknown error"}`;
                wrap.classList.add("text-red-600/70", "dark:text-red-400/70");
                break;
            default:
                wrap.textContent = `[${e.kind}]`;
                wrap.classList.add("text-dark-400", "dark:text-dark-500");
        }

        return wrap;
    }

    private toggleTechnicalMessages(container: HTMLElement): void {
        const messagesList = container.querySelector<HTMLElement>('[data-messages-list="1"]');
        const chevron = container.querySelector<SVGElement>('[data-chevron="1"]');

        if (!messagesList || !chevron) {
            return;
        }

        const isHidden = messagesList.classList.contains("hidden");

        if (isHidden) {
            messagesList.classList.remove("hidden");
            chevron.classList.add("rotate-180");
        } else {
            messagesList.classList.add("hidden");
            chevron.classList.remove("rotate-180");
        }
    }

    private updateTechnicalIndicator(container: HTMLElement, event: AgentEvent): void {
        const indicator = container.querySelector<HTMLElement>('[data-indicator="1"]');
        const label = container.querySelector<HTMLElement>('[data-label="1"]');
        const header = container.querySelector<HTMLElement>('[data-header="1"]');
        if (!indicator) {
            return;
        }

        // Pulse for active tool calls and inference - make it more prominent
        if (event.kind === "tool_calling" || event.kind === "inference_start") {
            indicator.classList.add("animate-pulse");
            indicator.classList.remove("bg-blue-400/50", "dark:bg-blue-500/50");
            indicator.classList.add(
                "bg-blue-500",
                "dark:bg-blue-400",
                "ring-2",
                "ring-blue-500/30",
                "dark:ring-blue-400/30",
            );
            // Pulse the text too
            if (label) {
                label.classList.add("animate-pulse", "text-blue-600", "dark:text-blue-400");
            }
            // Make header more prominent when active
            if (header) {
                header.classList.add("bg-blue-50/50", "dark:bg-blue-900/20");
            }
        } else if (event.kind === "tool_called" || event.kind === "inference_stop") {
            // Fade out pulse when tool completes, but keep it visible
            setTimeout(() => {
                if (indicator) {
                    indicator.classList.remove(
                        "animate-pulse",
                        "bg-blue-500",
                        "dark:bg-blue-400",
                        "ring-2",
                        "ring-blue-500/30",
                        "dark:ring-blue-400/30",
                    );
                    indicator.classList.add("bg-blue-400/70", "dark:bg-blue-500/70");
                }
                if (label) {
                    label.classList.remove("animate-pulse", "text-blue-600", "dark:text-blue-400");
                    label.classList.add("text-dark-600", "dark:text-dark-300");
                }
                if (header) {
                    header.classList.remove("bg-blue-50/50", "dark:bg-blue-900/20");
                }
            }, 800);
        }
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
