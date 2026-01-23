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
    contextUsage?: ContextUsageData;
}

interface ContextUsageData {
    usedTokens: number;
    maxTokens: number;
    modelName: string;
    inputTokens: number;
    outputTokens: number;
    inputCost: number;
    outputCost: number;
    totalCost: number;
}

interface RunResponse {
    sessionId?: string;
    error?: string;
}

interface ActiveSessionData {
    id: string;
    status: string;
    instruction: string;
    chunks: PollChunk[];
    lastChunkId: number;
}

interface TurnData {
    instruction: string;
    response: string;
    status: string;
    events: PollChunk[];
}

export default class extends Controller {
    static values = {
        runUrl: String,
        pollUrlTemplate: String,
        conversationId: String,
        contextUsageUrl: String,
        contextUsage: Object,
        activeSession: Object,
        turns: Array,
    };

    static targets = [
        "messages",
        "instruction",
        "submit",
        "autoScroll",
        "submitOnEnter",
        "contextUsage",
        "contextUsageText",
        "contextUsageBar",
        "contextUsageCost",
    ];

    declare readonly runUrlValue: string;
    declare readonly pollUrlTemplateValue: string;
    declare readonly conversationIdValue: string;
    declare readonly contextUsageUrlValue: string;
    declare readonly contextUsageValue: ContextUsageData;
    declare readonly activeSessionValue: ActiveSessionData | null;
    declare readonly turnsValue: TurnData[];

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
    declare readonly hasContextUsageTarget: boolean;
    declare readonly contextUsageTarget: HTMLElement;
    declare readonly hasContextUsageTextTarget: boolean;
    declare readonly contextUsageTextTarget: HTMLElement;
    declare readonly hasContextUsageBarTarget: boolean;
    declare readonly contextUsageBarTarget: HTMLElement;
    declare readonly hasContextUsageCostTarget: boolean;
    declare readonly contextUsageCostTarget: HTMLElement;

    private pollingIntervalId: ReturnType<typeof setInterval> | null = null;
    private contextUsageIntervalId: ReturnType<typeof setInterval> | null = null;
    private autoScrollEnabled: boolean = true;
    private submitOnEnterEnabled: boolean = true;

    connect(): void {
        const cu = this.contextUsageValue as ContextUsageData | undefined;
        if (cu && typeof cu.usedTokens === "number" && typeof cu.maxTokens === "number") {
            this.updateContextBar(cu);
        }
        this.startContextUsagePolling();

        // Render technical containers for completed turns
        this.renderCompletedTurnsTechnicalContainers();

        // Check for active session and resume if needed
        const activeSession = this.activeSessionValue as ActiveSessionData | null;
        if (activeSession && activeSession.id) {
            this.resumeActiveSession(activeSession);
        }
    }

    disconnect(): void {
        this.stopPolling();
        this.stopContextUsagePolling();
    }

    private startContextUsagePolling(): void {
        const url = this.contextUsageUrlValue;
        if (!url) {
            return;
        }
        const poll = async (): Promise<void> => {
            try {
                const res = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } });
                if (res.ok) {
                    const data = (await res.json()) as ContextUsageData;
                    this.updateContextBar(data);
                }
            } catch {
                // ignore
            }
        };
        poll();
        this.contextUsageIntervalId = setInterval(poll, 2500);
    }

    private stopContextUsagePolling(): void {
        if (this.contextUsageIntervalId !== null) {
            clearInterval(this.contextUsageIntervalId);
            this.contextUsageIntervalId = null;
        }
    }

    private updateContextBar(usage: ContextUsageData): void {
        if (this.hasContextUsageTextTarget) {
            this.contextUsageTextTarget.textContent = `${formatInt(usage.usedTokens)} of ${formatInt(usage.maxTokens)} tokens used`;
        }
        if (this.hasContextUsageBarTarget) {
            const pct = usage.maxTokens > 0 ? Math.min(100, (100 * usage.usedTokens) / usage.maxTokens) : 0;
            this.contextUsageBarTarget.style.width = `${pct}%`;
        }
        if (this.hasContextUsageCostTarget) {
            this.contextUsageCostTarget.textContent = `$${usage.totalCost.toFixed(4)}`;
        }
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

        this.setWorkingState();

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

    private startPolling(sessionId: string, container: HTMLElement, startingLastId: number = 0): void {
        let lastId = startingLastId;
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

                if (data.contextUsage) {
                    this.updateContextBar(data.contextUsage);
                }

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
        this.submitTarget.classList.remove("!bg-gradient-to-r", "!from-purple-500", "!to-blue-500", "animate-pulse");
    }

    private setWorkingState(): void {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
            this.submitTarget.innerHTML = '<span class="inline-flex items-center gap-1.5">✨ Working...</span>';
            this.submitTarget.classList.add("!bg-gradient-to-r", "!from-purple-500", "!to-blue-500", "animate-pulse");
        }
    }

    private renderCompletedTurnsTechnicalContainers(): void {
        if (!this.hasMessagesTarget) {
            return;
        }

        const turns = this.turnsValue || [];
        const activeSession = this.activeSessionValue as ActiveSessionData | null;
        const hasActiveSession = activeSession && activeSession.id;

        // Get all assistant response elements
        const assistantElements = this.messagesTarget.querySelectorAll<HTMLElement>(".flex.justify-start");

        // Process each turn (skip the last one if there's an active session - it will be handled by resumeActiveSession)
        const turnsToProcess = hasActiveSession ? turns.slice(0, -1) : turns;

        turnsToProcess.forEach((turn, index) => {
            // Only render if there are events to show
            if (!turn.events || turn.events.length === 0) {
                return;
            }

            const assistantEl = assistantElements[index];
            if (!assistantEl) {
                return;
            }

            // Get the inner container
            const innerContainer = assistantEl.querySelector<HTMLElement>(".max-w-\\[85\\%\\].rounded-lg.px-4.py-2");
            if (!innerContainer) {
                return;
            }

            // Create a wrapper for the technical container and response
            const existingContent = innerContainer.innerHTML;
            innerContainer.innerHTML = "";
            innerContainer.classList.add("space-y-2");

            // Create and add completed technical container
            const technicalContainer = this.createCompletedTechnicalContainer(turn);
            innerContainer.appendChild(technicalContainer);

            // Add back the response text
            if (existingContent) {
                const textEl = document.createElement("div");
                textEl.className = "whitespace-pre-wrap";
                textEl.innerHTML = existingContent;
                innerContainer.appendChild(textEl);
            }
        });
    }

    private createCompletedTechnicalContainer(turn: TurnData): HTMLElement {
        const container = document.createElement("div");
        container.className = "technical-messages-container";
        container.dataset.technicalMessages = "1";

        const isSuccess = turn.status === "completed";
        const headerBgClass = isSuccess
            ? "from-green-50/80 to-emerald-50/80 dark:from-green-900/20 dark:to-emerald-900/20 border-green-200/50 dark:border-green-700/30"
            : "from-red-50/80 to-rose-50/80 dark:from-red-900/20 dark:to-rose-900/20 border-red-200/50 dark:border-red-700/30";

        const header = document.createElement("button");
        header.type = "button";
        header.className = `flex items-center gap-2 w-full text-left py-2 px-3 rounded-lg bg-gradient-to-r ${headerBgClass} hover:opacity-90 transition-all duration-300`;
        header.dataset.header = "1";
        header.addEventListener("click", () => {
            this.toggleTechnicalMessages(container);
        });

        const indicatorWrapper = document.createElement("div");
        indicatorWrapper.className = "relative flex-shrink-0";

        const indicator = document.createElement("div");
        indicator.className = isSuccess
            ? "technical-indicator w-2.5 h-2.5 rounded-full bg-green-500 dark:bg-green-400"
            : "technical-indicator w-2.5 h-2.5 rounded-full bg-red-500 dark:bg-red-400";
        indicator.dataset.indicator = "1";

        indicatorWrapper.appendChild(indicator);

        const labelWrapper = document.createElement("div");
        labelWrapper.className = "flex items-center gap-1.5";

        const sparkle = document.createElement("span");
        sparkle.className = "text-xs";
        sparkle.textContent = isSuccess ? "✅" : "❌";

        const label = document.createElement("span");
        const labelColorClass = isSuccess
            ? "from-green-600 to-green-600 dark:from-green-400 dark:to-green-400"
            : "from-red-600 to-red-600 dark:from-red-400 dark:to-red-400";
        label.className = `text-[11px] font-semibold bg-gradient-to-r ${labelColorClass} bg-clip-text text-transparent`;
        label.innerHTML = isSuccess ? "Done" : "Failed";
        label.dataset.label = "1";

        labelWrapper.appendChild(sparkle);
        labelWrapper.appendChild(label);

        const count = document.createElement("span");
        const countColorClass = isSuccess
            ? "text-green-500 dark:text-green-400 bg-green-100/50 dark:bg-green-900/30"
            : "text-red-500 dark:text-red-400 bg-red-100/50 dark:bg-red-900/30";
        count.className = `text-[10px] ${countColorClass} ml-auto font-medium px-1.5 py-0.5 rounded-full`;
        count.dataset.count = "1";
        count.textContent = String(turn.events.length);

        const chevron = document.createElement("svg");
        const chevronColorClass = isSuccess
            ? "text-green-400 dark:text-green-500"
            : "text-red-400 dark:text-red-500";
        chevron.className = `w-3 h-3 ${chevronColorClass} transition-transform duration-300`;
        chevron.dataset.chevron = "1";
        chevron.innerHTML = '<path fill="currentColor" d="M6 9l6 6 6-6H6z"/>';
        chevron.setAttribute("viewBox", "0 0 24 24");

        header.appendChild(indicatorWrapper);
        header.appendChild(labelWrapper);
        header.appendChild(count);
        header.appendChild(chevron);

        const messagesList = document.createElement("div");
        const messagesListBorderClass = isSuccess
            ? "border-green-200/30 dark:border-green-700/20"
            : "border-red-200/30 dark:border-red-700/20";
        messagesList.className = `technical-messages-list max-h-[120px] overflow-y-auto space-y-1 px-3 py-2 hidden bg-white/50 dark:bg-dark-800/50 rounded-b-lg border-x border-b ${messagesListBorderClass}`;
        messagesList.dataset.messagesList = "1";

        // Render all events
        for (const eventChunk of turn.events) {
            const payload = JSON.parse(eventChunk.payload) as {
                kind?: string;
                toolName?: string;
                toolInputs?: Array<{ key: string; value: string }>;
                toolResult?: string;
                errorMessage?: string;
            };

            const event: AgentEvent = {
                kind: payload.kind ?? "unknown",
                toolName: payload.toolName,
                toolInputs: payload.toolInputs,
                toolResult: payload.toolResult,
                errorMessage: payload.errorMessage,
            };

            const eventEl = this.renderTechnicalEvent(event);
            messagesList.appendChild(eventEl);
        }

        container.appendChild(header);
        container.appendChild(messagesList);

        return container;
    }

    private resumeActiveSession(activeSession: ActiveSessionData): void {
        if (!this.hasMessagesTarget || !this.hasSubmitTarget) {
            return;
        }

        // Find the last assistant response element (server-rendered for the active session)
        const assistantElements = this.messagesTarget.querySelectorAll<HTMLElement>(".flex.justify-start");
        const lastAssistantEl = assistantElements[assistantElements.length - 1];

        if (!lastAssistantEl) {
            return;
        }

        // Get the inner container (the div with the response)
        const existingInner = lastAssistantEl.querySelector<HTMLElement>(".max-w-\\[85\\%\\].rounded-lg.px-4.py-2");

        if (!existingInner) {
            return;
        }

        // Clear the existing content and set up for streaming
        existingInner.innerHTML = "";
        existingInner.classList.add("space-y-2");

        // Create technical messages container
        const technicalContainer = this.createTechnicalMessagesContainer();
        existingInner.appendChild(technicalContainer);

        // Set submit button to working state
        this.setWorkingState();

        // Replay existing chunks
        for (const chunk of activeSession.chunks) {
            // Check if session already completed while we were away
            if (this.handleChunk(chunk, existingInner)) {
                // Session is done, don't start polling
                return;
            }
        }

        // Start polling from where we left off
        this.startPolling(activeSession.id, existingInner, activeSession.lastChunkId);
        this.scrollToBottom();
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
                this.markTechnicalContainerComplete(container, false);
            } else {
                this.markTechnicalContainerComplete(container, true);
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
            "flex items-center gap-2 w-full text-left py-2 px-3 rounded-lg bg-gradient-to-r from-purple-50/80 to-blue-50/80 dark:from-purple-900/20 dark:to-blue-900/20 border border-purple-200/50 dark:border-purple-700/30 hover:from-purple-100/80 hover:to-blue-100/80 dark:hover:from-purple-900/30 dark:hover:to-blue-900/30 transition-all duration-300";
        header.dataset.header = "1";
        header.addEventListener("click", () => {
            this.toggleTechnicalMessages(container);
        });

        const indicatorWrapper = document.createElement("div");
        indicatorWrapper.className = "relative flex-shrink-0";

        const indicator = document.createElement("div");
        indicator.className =
            "technical-indicator w-2.5 h-2.5 rounded-full bg-gradient-to-r from-purple-500 to-blue-500 dark:from-purple-400 dark:to-blue-400 animate-pulse";
        indicator.dataset.indicator = "1";

        const indicatorGlow = document.createElement("div");
        indicatorGlow.className =
            "absolute inset-0 w-2.5 h-2.5 rounded-full bg-gradient-to-r from-purple-500 to-blue-500 dark:from-purple-400 dark:to-blue-400 animate-ping opacity-75";

        indicatorWrapper.appendChild(indicator);
        indicatorWrapper.appendChild(indicatorGlow);

        const labelWrapper = document.createElement("div");
        labelWrapper.className = "flex items-center gap-1.5";

        const sparkle = document.createElement("span");
        sparkle.className = "text-xs";
        sparkle.textContent = "✨";

        const label = document.createElement("span");
        label.className =
            "text-[11px] font-semibold bg-gradient-to-r from-purple-600 to-blue-600 dark:from-purple-400 dark:to-blue-400 bg-clip-text text-transparent";
        label.innerHTML = "Working...";
        label.dataset.label = "1";

        labelWrapper.appendChild(sparkle);
        labelWrapper.appendChild(label);

        const count = document.createElement("span");
        count.className =
            "text-[10px] text-purple-500 dark:text-purple-400 ml-auto font-medium bg-purple-100/50 dark:bg-purple-900/30 px-1.5 py-0.5 rounded-full";
        count.dataset.count = "1";
        count.textContent = "0";

        const chevron = document.createElement("svg");
        chevron.className = "w-3 h-3 text-purple-400 dark:text-purple-500 transition-transform duration-300";
        chevron.dataset.chevron = "1";
        chevron.innerHTML = '<path fill="currentColor" d="M6 9l6 6 6-6H6z"/>';
        chevron.setAttribute("viewBox", "0 0 24 24");

        header.appendChild(indicatorWrapper);
        header.appendChild(labelWrapper);
        header.appendChild(count);
        header.appendChild(chevron);

        const messagesList = document.createElement("div");
        messagesList.className =
            "technical-messages-list max-h-[120px] overflow-y-auto space-y-1 px-3 py-2 hidden bg-white/50 dark:bg-dark-800/50 rounded-b-lg border-x border-b border-purple-200/30 dark:border-purple-700/20";
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
        const indicatorGlow = indicator?.nextElementSibling as HTMLElement | null;
        const label = container.querySelector<HTMLElement>('[data-label="1"]');
        const header = container.querySelector<HTMLElement>('[data-header="1"]');

        if (!indicator) {
            return;
        }

        // Intensify animations for active tool calls and inference
        if (event.kind === "tool_calling" || event.kind === "inference_start") {
            // Make indicator more vibrant
            indicator.classList.add("scale-125");
            if (indicatorGlow) {
                indicatorGlow.classList.remove("opacity-75");
                indicatorGlow.classList.add("opacity-100");
            }
            // Make header glow more intensely
            if (header) {
                header.classList.add(
                    "shadow-lg",
                    "shadow-purple-200/50",
                    "dark:shadow-purple-900/30",
                    "border-purple-300/70",
                    "dark:border-purple-600/50",
                );
            }
        } else if (event.kind === "tool_called" || event.kind === "inference_stop") {
            // Return to normal animation intensity
            setTimeout(() => {
                if (indicator) {
                    indicator.classList.remove("scale-125");
                }
                if (indicatorGlow) {
                    indicatorGlow.classList.remove("opacity-100");
                    indicatorGlow.classList.add("opacity-75");
                }
                if (header) {
                    header.classList.remove(
                        "shadow-lg",
                        "shadow-purple-200/50",
                        "dark:shadow-purple-900/30",
                        "border-purple-300/70",
                        "dark:border-purple-600/50",
                    );
                }
            }, 500);
        } else if (event.kind === "agent_error") {
            // Show error state
            indicator.classList.remove(
                "bg-gradient-to-r",
                "from-purple-500",
                "to-blue-500",
                "dark:from-purple-400",
                "dark:to-blue-400",
            );
            indicator.classList.add("bg-red-500", "dark:bg-red-400");
            if (indicatorGlow) {
                indicatorGlow.classList.add("hidden");
            }
            if (label) {
                label.innerHTML = "Error occurred";
                label.classList.remove("from-purple-600", "to-blue-600", "dark:from-purple-400", "dark:to-blue-400");
                label.classList.add("from-red-600", "to-red-600", "dark:from-red-400", "dark:to-red-400");
            }
        }
    }

    private markTechnicalContainerComplete(container: HTMLElement, success: boolean): void {
        const technicalContainer = this.getTechnicalMessagesContainer(container);
        if (!technicalContainer) {
            return;
        }

        const indicator = technicalContainer.querySelector<HTMLElement>('[data-indicator="1"]');
        const indicatorGlow = indicator?.nextElementSibling as HTMLElement | null;
        const label = technicalContainer.querySelector<HTMLElement>('[data-label="1"]');
        const header = technicalContainer.querySelector<HTMLElement>('[data-header="1"]');
        const sparkle = label?.previousElementSibling as HTMLElement | null;

        // Stop all animations
        if (indicator) {
            indicator.classList.remove("animate-pulse", "scale-125");
        }
        if (indicatorGlow) {
            indicatorGlow.classList.add("hidden");
        }

        if (success) {
            // Success state - green checkmark vibes
            if (indicator) {
                indicator.classList.remove(
                    "bg-gradient-to-r",
                    "from-purple-500",
                    "to-blue-500",
                    "dark:from-purple-400",
                    "dark:to-blue-400",
                );
                indicator.classList.add("bg-green-500", "dark:bg-green-400");
            }
            if (label) {
                label.innerHTML = "Done";
                label.classList.remove("from-purple-600", "to-blue-600", "dark:from-purple-400", "dark:to-blue-400");
                label.classList.add("from-green-600", "to-green-600", "dark:from-green-400", "dark:to-green-400");
            }
            if (sparkle) {
                sparkle.textContent = "✅";
            }
            if (header) {
                header.classList.remove(
                    "from-purple-50/80",
                    "to-blue-50/80",
                    "dark:from-purple-900/20",
                    "dark:to-blue-900/20",
                    "border-purple-200/50",
                    "dark:border-purple-700/30",
                    "hover:from-purple-100/80",
                    "hover:to-blue-100/80",
                    "dark:hover:from-purple-900/30",
                    "dark:hover:to-blue-900/30",
                );
                header.classList.add(
                    "from-green-50/80",
                    "to-emerald-50/80",
                    "dark:from-green-900/20",
                    "dark:to-emerald-900/20",
                    "border-green-200/50",
                    "dark:border-green-700/30",
                );
            }
        } else {
            // Error state - red
            if (indicator) {
                indicator.classList.remove(
                    "bg-gradient-to-r",
                    "from-purple-500",
                    "to-blue-500",
                    "dark:from-purple-400",
                    "dark:to-blue-400",
                );
                indicator.classList.add("bg-red-500", "dark:bg-red-400");
            }
            if (label) {
                label.innerHTML = "Failed";
                label.classList.remove("from-purple-600", "to-blue-600", "dark:from-purple-400", "dark:to-blue-400");
                label.classList.add("from-red-600", "to-red-600", "dark:from-red-400", "dark:to-red-400");
            }
            if (sparkle) {
                sparkle.textContent = "❌";
            }
            if (header) {
                header.classList.remove(
                    "from-purple-50/80",
                    "to-blue-50/80",
                    "dark:from-purple-900/20",
                    "dark:to-blue-900/20",
                    "border-purple-200/50",
                    "dark:border-purple-700/30",
                    "hover:from-purple-100/80",
                    "hover:to-blue-100/80",
                    "dark:hover:from-purple-900/30",
                    "dark:hover:to-blue-900/30",
                );
                header.classList.add(
                    "from-red-50/80",
                    "to-rose-50/80",
                    "dark:from-red-900/20",
                    "dark:to-rose-900/20",
                    "border-red-200/50",
                    "dark:border-red-700/30",
                );
            }
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

function formatInt(n: number): string {
    return n.toLocaleString("en-US", { maximumFractionDigits: 0 });
}
