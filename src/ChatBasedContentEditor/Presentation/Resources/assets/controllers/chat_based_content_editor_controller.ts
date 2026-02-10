import { Controller } from "@hotwired/stimulus";
import {
    type AgentEvent,
    type PollChunk,
    type PollResponse,
    type ContextUsageData,
    type RunResponse,
    type ActiveSessionData,
    type TurnData,
    escapeHtml,
    formatInt,
    parseChunkPayload,
    payloadToAgentEvent,
    getCancelledContainerStyle,
    getCompletedContainerStyle,
    getWorkingContainerStyle,
    getProgressAnimationState,
} from "./chat_editor_helpers.ts";
import { renderMarkdown } from "./markdown_renderer.ts";

const PROGRESS_MAX_LINES = 10;

interface TranslationsData {
    aiBudget: string;
    estimatedCost: string;
    sendError: string;
    networkError: string;
    pollError: string;
    connectionRetry: string;
    makeChanges: string;
    makingChanges: string;
    noResponse: string;
    working: string;
    thinking: string;
    askingAi: string;
    aiResponseReceived: string;
    unknownError: string;
    allSet: string;
    inProgress: string;
    stop: string;
    stopping: string;
    cancelled: string;
}

export default class extends Controller {
    static values = {
        runUrl: String,
        pollUrlTemplate: String,
        cancelUrlTemplate: String,
        conversationId: String,
        contextUsageUrl: String,
        contextUsage: Object,
        activeSession: Object,
        turns: Array,
        readOnly: { type: Boolean, default: false },
        translations: Object,
    };

    static targets = [
        "messages",
        "instruction",
        "submit",
        "cancelButton",
        "autoScroll",
        "submitOnEnter",
        "contextUsage",
        "contextUsageText",
        "contextUsageBar",
        "contextUsageCost",
    ];

    declare readonly runUrlValue: string;
    declare readonly pollUrlTemplateValue: string;
    declare readonly cancelUrlTemplateValue: string;
    declare readonly conversationIdValue: string;
    declare readonly contextUsageUrlValue: string;
    declare readonly contextUsageValue: ContextUsageData;
    declare readonly activeSessionValue: ActiveSessionData | null;
    declare readonly turnsValue: TurnData[];
    declare readonly readOnlyValue: boolean;
    declare readonly translationsValue: TranslationsData;

    declare readonly hasMessagesTarget: boolean;
    declare readonly messagesTarget: HTMLElement;
    declare readonly hasInstructionTarget: boolean;
    declare readonly instructionTarget: HTMLTextAreaElement;
    declare readonly hasSubmitTarget: boolean;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly hasCancelButtonTarget: boolean;
    declare readonly cancelButtonTarget: HTMLButtonElement;
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

    private pollingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private contextUsageTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private autoScrollEnabled: boolean = true;
    private submitOnEnterEnabled: boolean = true;
    private isContextUsagePollingActive: boolean = false;
    private isPollingActive: boolean = false;

    // Activity indicators state (Working/Thinking badges)
    private activityThinkingTimerId: ReturnType<typeof setInterval> | null = null;
    private activityWorkingTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private activityToolCallCount: number = 0;
    private activityThinkingSeconds: number = 0;
    private activityWorkingActive: boolean = false;

    connect(): void {
        // Render technical containers for completed turns (always, shared between editor and read-only)
        this.renderCompletedTurnsTechnicalContainers();

        // Skip interactive features in read-only mode
        if (this.readOnlyValue) {
            return;
        }

        const cu = this.contextUsageValue as ContextUsageData | undefined;
        if (cu && typeof cu.usedTokens === "number" && typeof cu.maxTokens === "number") {
            this.updateContextBar(cu);
        }
        this.startContextUsagePolling();

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
        this.isContextUsagePollingActive = true;
        this.pollContextUsage();
    }

    private async pollContextUsage(): Promise<void> {
        let url = this.contextUsageUrlValue;
        if (this.currentPollingState) {
            url += `?sessionId=${encodeURIComponent(this.currentPollingState.sessionId)}`;
        }
        try {
            const res = await fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } });
            if (res.ok) {
                const data = (await res.json()) as ContextUsageData;
                this.updateContextBar(data);
            }
        } catch {
            // ignore
        }

        // Schedule next poll only after this one completes (non-overlapping)
        if (this.isContextUsagePollingActive) {
            this.contextUsageTimeoutId = setTimeout(() => this.pollContextUsage(), 2500);
        }
    }

    private stopContextUsagePolling(): void {
        this.isContextUsagePollingActive = false;
        if (this.contextUsageTimeoutId !== null) {
            clearTimeout(this.contextUsageTimeoutId);
            this.contextUsageTimeoutId = null;
        }
    }

    private updateContextBar(usage: ContextUsageData): void {
        if (this.hasContextUsageTextTarget) {
            const t = this.translationsValue;
            const text = t.aiBudget
                .replace("%used%", formatInt(usage.usedTokens))
                .replace("%max%", formatInt(usage.maxTokens));
            this.contextUsageTextTarget.textContent = text;
        }
        if (this.hasContextUsageBarTarget) {
            const pct = usage.maxTokens > 0 ? Math.min(100, (100 * usage.usedTokens) / usage.maxTokens) : 0;
            this.contextUsageBarTarget.style.width = `${pct}%`;
        }
        if (this.hasContextUsageCostTarget) {
            const t = this.translationsValue;
            this.contextUsageCostTarget.textContent = t.estimatedCost.replace("%cost%", usage.totalCost.toFixed(4));
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
                const t = this.translationsValue;
                const msg = data.error || t.sendError.replace("%status%", String(response.status));
                this.appendError(inner, msg);
                this.resetSubmitButton();

                return;
            }

            this.startPolling(data.sessionId, inner);
        } catch (err) {
            const t = this.translationsValue;
            const msg = err instanceof Error ? err.message : t.networkError;
            this.appendError(inner, msg);
            this.resetSubmitButton();
        }
    }

    async handleCancel(): Promise<void> {
        if (!this.currentPollingState || !this.cancelUrlTemplateValue) {
            return;
        }

        const cancelUrl = this.cancelUrlTemplateValue.replace("__SESSION_ID__", this.currentPollingState.sessionId);
        const t = this.translationsValue;

        // Disable immediately to prevent double-clicks
        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.disabled = true;
            this.cancelButtonTarget.textContent = t.stopping;
        }

        try {
            const csrfInput = document.querySelector('input[name="_csrf_token"]') as HTMLInputElement | null;

            const formData = new FormData();
            if (csrfInput) {
                formData.append("_csrf_token", csrfInput.value);
            }

            await fetch(cancelUrl, {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData,
            });

            // Don't stop polling — let the polling loop detect the cancelled status
            // and done chunk naturally, so all pre-cancellation output is displayed.
        } catch {
            // If the cancel request fails, re-enable the button so the user can retry
            if (this.hasCancelButtonTarget) {
                this.cancelButtonTarget.disabled = false;
                this.cancelButtonTarget.textContent = t.stop;
            }
        }
    }

    private startPolling(sessionId: string, container: HTMLElement, startingLastId: number = 0): void {
        this.currentPollingState = {
            sessionId,
            container,
            lastId: startingLastId,
            pollUrl: this.pollUrlTemplateValue.replace("__SESSION_ID__", sessionId),
        };
        this.isPollingActive = true;
        this.pollSession();
    }

    private currentPollingState: {
        sessionId: string;
        container: HTMLElement;
        lastId: number;
        pollUrl: string;
    } | null = null;

    private async pollSession(): Promise<void> {
        if (!this.currentPollingState || !this.isPollingActive) {
            return;
        }

        const { container, pollUrl } = this.currentPollingState;

        try {
            const response = await fetch(`${pollUrl}?after=${this.currentPollingState.lastId}`, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });

            if (!response.ok) {
                const t = this.translationsValue;
                this.appendError(container, t.pollError.replace("%status%", String(response.status)));
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

            this.currentPollingState.lastId = data.lastId;

            if (data.contextUsage) {
                this.updateContextBar(data.contextUsage);
            }

            if (data.status === "completed" || data.status === "failed" || data.status === "cancelled") {
                this.stopPolling();
                this.resetSubmitButton();

                return;
            }
        } catch (err) {
            const t = this.translationsValue;
            const msg = err instanceof Error ? err.message : t.connectionRetry;
            this.appendError(container, msg);
            this.stopPolling();
            this.resetSubmitButton();

            return;
        }

        // Schedule next poll only after this one completes (non-overlapping)
        if (this.isPollingActive) {
            this.pollingTimeoutId = setTimeout(() => this.pollSession(), 500);
        }
    }

    private stopPolling(): void {
        this.isPollingActive = false;
        if (this.pollingTimeoutId !== null) {
            clearTimeout(this.pollingTimeoutId);
            this.pollingTimeoutId = null;
        }
        this.currentPollingState = null;
    }

    private resetSubmitButton(): void {
        const t = this.translationsValue;
        this.submitTarget.disabled = false;
        this.submitTarget.textContent = t.makeChanges;
        this.submitTarget.classList.remove("!bg-gradient-to-r", "!from-purple-500", "!to-blue-500", "animate-pulse");
        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.classList.add("hidden");
        }
    }

    private setWorkingState(): void {
        if (this.hasSubmitTarget) {
            const t = this.translationsValue;
            this.submitTarget.disabled = true;
            this.submitTarget.innerHTML = `<span class="inline-flex items-center gap-1.5">✨ ${escapeHtml(t.makingChanges)}</span>`;
            this.submitTarget.classList.add("!bg-gradient-to-r", "!from-purple-500", "!to-blue-500", "animate-pulse");
        }
        if (this.hasCancelButtonTarget) {
            this.cancelButtonTarget.classList.remove("hidden");
            this.cancelButtonTarget.disabled = false;
            const t = this.translationsValue;
            this.cancelButtonTarget.textContent = t.stop;
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
            const assistantEl = assistantElements[index];
            if (!assistantEl) {
                return;
            }

            // Get the inner container
            const innerContainer = assistantEl.querySelector<HTMLElement>(".max-w-\\[85\\%\\].rounded-lg.px-4.py-2");
            if (!innerContainer) {
                return;
            }

            // Clear the container and set up structure
            innerContainer.innerHTML = "";
            innerContainer.classList.add("space-y-2");

            const isCancelled = turn.status === "cancelled";

            // Create and add completed technical container if there are events
            if (turn.events && turn.events.length > 0) {
                const technicalContainer = this.createCompletedTechnicalContainer(turn);
                innerContainer.appendChild(technicalContainer);
            }

            // For cancelled turns, show a distinct "Cancelled" indicator
            if (isCancelled) {
                const t = this.translationsValue;
                const cancelledEl = document.createElement("div");
                cancelledEl.className = "whitespace-pre-wrap text-amber-600 dark:text-amber-400 italic";
                cancelledEl.textContent = t.cancelled;
                innerContainer.appendChild(cancelledEl);
            } else {
                // Render the response text as markdown
                const responseText = turn.response || innerContainer.dataset.turnResponse || "";
                if (responseText) {
                    const textEl = document.createElement("div");
                    textEl.className = "whitespace-pre-wrap";
                    textEl.innerHTML = renderMarkdown(responseText, { streaming: false });
                    innerContainer.appendChild(textEl);
                } else {
                    const t = this.translationsValue;
                    const textEl = document.createElement("div");
                    textEl.className = "whitespace-pre-wrap text-dark-500 dark:text-dark-400";
                    textEl.textContent = t.noResponse;
                    innerContainer.appendChild(textEl);
                }
            }
        });
    }

    private createCompletedTechnicalContainer(turn: TurnData): HTMLElement {
        const container = document.createElement("div");
        container.className = "technical-messages-container";
        container.dataset.technicalMessages = "1";

        const style = turn.status === "cancelled" ? getCancelledContainerStyle() : getCompletedContainerStyle();

        const header = document.createElement("button");
        header.type = "button";
        header.className = `flex items-center gap-2 w-full text-left py-2 px-3 rounded-lg bg-gradient-to-r ${style.headerBgClass} hover:opacity-90 transition-all duration-300`;
        header.dataset.header = "1";
        header.addEventListener("click", () => {
            this.toggleTechnicalMessages(container);
        });

        const indicatorWrapper = document.createElement("div");
        indicatorWrapper.className = "relative flex-shrink-0";

        const indicator = document.createElement("div");
        indicator.className = `technical-indicator w-2.5 h-2.5 rounded-full ${style.indicatorClass}`;
        indicator.dataset.indicator = "1";

        indicatorWrapper.appendChild(indicator);

        const labelWrapper = document.createElement("div");
        labelWrapper.className = "flex items-center gap-1.5";

        const sparkle = document.createElement("span");
        sparkle.className = "text-xs";
        sparkle.textContent = style.sparkleEmoji;

        const label = document.createElement("span");
        label.className = `text-[11px] font-semibold bg-gradient-to-r ${style.labelColorClass} bg-clip-text text-transparent`;
        label.innerHTML = style.labelText;
        label.dataset.label = "1";

        labelWrapper.appendChild(sparkle);
        labelWrapper.appendChild(label);

        // Count tool_calling events for the Working badge
        const toolCallCount = turn.events.filter((e) => {
            const payload = parseChunkPayload(e.payload);
            return payload.kind === "tool_calling";
        }).length;

        // Activity badges (both inactive for completed containers)
        const badgesWrapper = this.createCompletedActivityBadges(toolCallCount);

        const chevron = document.createElement("svg");
        chevron.className = `w-3 h-3 ${style.chevronColorClass} transition-transform duration-300`;
        chevron.dataset.chevron = "1";
        chevron.innerHTML = '<path fill="currentColor" d="M6 9l6 6 6-6H6z"/>';
        chevron.setAttribute("viewBox", "0 0 24 24");

        header.appendChild(indicatorWrapper);
        header.appendChild(labelWrapper);
        header.appendChild(badgesWrapper);
        header.appendChild(chevron);

        const messagesList = document.createElement("div");
        messagesList.className = `technical-messages-list max-h-[120px] overflow-y-auto space-y-1 px-3 py-2 hidden bg-white/50 dark:bg-dark-800/50 rounded-b-lg border-x border-b ${style.messagesListBorderClass}`;
        messagesList.dataset.messagesList = "1";

        // Render all events
        for (const eventChunk of turn.events) {
            const payload = parseChunkPayload(eventChunk.payload);
            const event = payloadToAgentEvent(payload);
            const eventEl = this.renderTechnicalEvent(event);
            messagesList.appendChild(eventEl);
        }

        container.appendChild(header);
        container.appendChild(messagesList);

        return container;
    }

    private createCompletedActivityBadges(toolCallCount: number): HTMLElement {
        const badgesWrapper = document.createElement("div");
        badgesWrapper.className = "flex items-center gap-1.5 ml-auto";
        badgesWrapper.dataset.activityBadges = "1";

        const t = this.translationsValue;

        // Working badge (inactive, shows tool call count)
        const workingBadge = document.createElement("span");
        workingBadge.className = "activity-badge activity-badge-inactive";
        workingBadge.dataset.activityWorkingBadge = "1";

        const workingLabel = document.createTextNode(t.working);
        workingBadge.appendChild(workingLabel);

        const workingCount = document.createElement("span");
        workingCount.className = "activity-seconds activity-seconds-inactive";
        workingCount.dataset.activityWorkingCount = "1";
        workingCount.textContent = String(toolCallCount);
        workingBadge.appendChild(workingCount);

        // Thinking badge (inactive, shows dash for completed)
        const thinkingBadge = document.createElement("span");
        thinkingBadge.className = "activity-badge activity-badge-inactive";
        thinkingBadge.dataset.activityThinkingBadge = "1";

        const thinkingLabel = document.createTextNode(t.thinking);
        thinkingBadge.appendChild(thinkingLabel);

        const thinkingSeconds = document.createElement("span");
        thinkingSeconds.className = "activity-seconds activity-seconds-inactive";
        thinkingSeconds.dataset.activityThinkingSeconds = "1";
        thinkingSeconds.textContent = "—";
        thinkingBadge.appendChild(thinkingSeconds);

        badgesWrapper.appendChild(workingBadge);
        badgesWrapper.appendChild(thinkingBadge);

        return badgesWrapper;
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
        const payload = parseChunkPayload(chunk.payload);

        if (chunk.chunkType === "text" && payload.content) {
            const textEl = this.getOrCreateTextElement(container);
            // Append to raw markdown content stored in data attribute
            const currentRaw = textEl.dataset.rawMarkdown || "";
            const newRaw = currentRaw + payload.content;
            textEl.dataset.rawMarkdown = newRaw;
            // Render markdown to HTML
            textEl.innerHTML = renderMarkdown(newRaw, { streaming: true });
            this.scrollToBottom();
        } else if (chunk.chunkType === "progress" && payload.message) {
            this.appendProgressLine(container, payload.message);
            this.scrollToBottom();
        } else if (chunk.chunkType === "event") {
            const event = payloadToAgentEvent(payload);
            this.appendTechnicalEvent(container, event);
            this.scrollToBottom();
        } else if (chunk.chunkType === "done") {
            const isCancellation = payload.success === false && payload.errorMessage?.includes("Cancelled");
            if (payload.success === false && payload.errorMessage && !isCancellation) {
                this.appendError(container, payload.errorMessage);
            }
            if (isCancellation) {
                const t = this.translationsValue;
                const cancelledEl = document.createElement("div");
                cancelledEl.className = "whitespace-pre-wrap text-amber-600 dark:text-amber-400 italic";
                cancelledEl.textContent = t.cancelled;
                container.appendChild(cancelledEl);
            }
            this.markTechnicalContainerComplete(container, isCancellation);
            this.scrollToBottom();

            return true;
        }

        return false;
    }

    private getOrCreateProgressWrapper(container: HTMLElement): HTMLElement {
        const existing = container.querySelector<HTMLElement>('[data-progress-wrapper="1"]');
        if (existing) {
            return existing;
        }
        const wrapper = document.createElement("div");
        wrapper.className = "relative overflow-hidden max-h-[12.5rem] rounded";
        wrapper.dataset.progressWrapper = "1";

        const fadeOverlay = document.createElement("div");
        fadeOverlay.className =
            "absolute inset-x-0 top-0 h-10 pointer-events-none z-10 bg-gradient-to-b from-dark-100 to-transparent dark:from-dark-700";
        fadeOverlay.setAttribute("aria-hidden", "true");

        const progressContainer = document.createElement("div");
        progressContainer.className = "space-y-0.5 pr-2";
        progressContainer.dataset.progressContainer = "1";

        wrapper.appendChild(fadeOverlay);
        wrapper.appendChild(progressContainer);

        const textEl = container.querySelector<HTMLElement>('[data-text-stream="1"]');
        if (textEl) {
            container.insertBefore(wrapper, textEl);
        } else {
            container.appendChild(wrapper);
        }
        return progressContainer;
    }

    private getOrCreateProgressContainer(container: HTMLElement): HTMLElement {
        const wrapper = container.querySelector<HTMLElement>('[data-progress-wrapper="1"]');
        if (wrapper) {
            const inner = wrapper.querySelector<HTMLElement>('[data-progress-container="1"]');
            if (inner) {
                return inner;
            }
        }
        return this.getOrCreateProgressWrapper(container);
    }

    private appendProgressLine(container: HTMLElement, message: string): void {
        const progressContainer = this.getOrCreateProgressContainer(container);
        while (progressContainer.children.length >= PROGRESS_MAX_LINES) {
            progressContainer.firstElementChild?.remove();
        }
        const line = document.createElement("div");
        line.className = "text-sm text-dark-500 dark:text-dark-400 italic leading-tight";
        line.textContent = message;
        progressContainer.appendChild(line);
    }

    private getOrCreateTextElement(container: HTMLElement): HTMLElement {
        const existing = container.querySelector<HTMLElement>('[data-text-stream="1"]');
        if (existing) {
            return existing;
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

        const style = getWorkingContainerStyle();

        const header = document.createElement("button");
        header.type = "button";
        header.className = `flex items-center gap-2 w-full text-left py-2 px-3 rounded-lg bg-gradient-to-r ${style.headerBgClass} border hover:from-purple-100/80 hover:to-blue-100/80 dark:hover:from-purple-900/30 dark:hover:to-blue-900/30 transition-all duration-300`;
        header.dataset.header = "1";
        header.addEventListener("click", () => {
            this.toggleTechnicalMessages(container);
        });

        const indicatorWrapper = document.createElement("div");
        indicatorWrapper.className = "relative flex-shrink-0";

        const indicator = document.createElement("div");
        indicator.className = `technical-indicator w-2.5 h-2.5 rounded-full ${style.indicatorClass} animate-pulse`;
        indicator.dataset.indicator = "1";

        const indicatorGlow = document.createElement("div");
        indicatorGlow.className = `absolute inset-0 w-2.5 h-2.5 rounded-full ${style.indicatorClass} animate-ping opacity-75`;

        indicatorWrapper.appendChild(indicator);
        indicatorWrapper.appendChild(indicatorGlow);

        const labelWrapper = document.createElement("div");
        labelWrapper.className = "flex items-center gap-1.5";

        const sparkle = document.createElement("span");
        sparkle.className = "text-xs";
        sparkle.textContent = style.sparkleEmoji;

        const label = document.createElement("span");
        label.className = `text-[11px] font-semibold bg-gradient-to-r ${style.labelColorClass} bg-clip-text text-transparent`;
        label.innerHTML = style.labelText;
        label.dataset.label = "1";

        labelWrapper.appendChild(sparkle);
        labelWrapper.appendChild(label);

        // Activity indicators (Working/Thinking badges) - right-aligned in header
        const badgesWrapper = this.createActivityBadges(container);

        const chevron = document.createElement("svg");
        chevron.className = `w-3 h-3 ${style.chevronColorClass} transition-transform duration-300`;
        chevron.dataset.chevron = "1";
        chevron.innerHTML = '<path fill="currentColor" d="M6 9l6 6 6-6H6z"/>';
        chevron.setAttribute("viewBox", "0 0 24 24");

        header.appendChild(indicatorWrapper);
        header.appendChild(labelWrapper);
        header.appendChild(badgesWrapper);
        header.appendChild(chevron);

        const messagesList = document.createElement("div");
        messagesList.className = `technical-messages-list max-h-[120px] overflow-y-auto space-y-1 px-3 py-2 hidden bg-white/50 dark:bg-dark-800/50 rounded-b-lg border-x border-b ${style.messagesListBorderClass}`;
        messagesList.dataset.messagesList = "1";

        container.appendChild(header);
        container.appendChild(messagesList);

        return container;
    }

    private createActivityBadges(container: HTMLElement): HTMLElement {
        const badgesWrapper = document.createElement("div");
        badgesWrapper.className = "flex items-center gap-1.5 ml-auto";
        badgesWrapper.dataset.activityBadges = "1";

        const t = this.translationsValue;

        // Working badge (starts inactive, counts tool calls)
        const workingBadge = document.createElement("span");
        workingBadge.className = "activity-badge activity-badge-inactive";
        workingBadge.dataset.activityWorkingBadge = "1";

        const workingLabel = document.createTextNode(t.working);
        workingBadge.appendChild(workingLabel);

        const workingCount = document.createElement("span");
        workingCount.className = "activity-seconds activity-seconds-inactive";
        workingCount.dataset.activityWorkingCount = "1";
        workingCount.textContent = "0";
        workingBadge.appendChild(workingCount);

        // Thinking badge (starts active, counts seconds)
        const thinkingBadge = document.createElement("span");
        thinkingBadge.className = "activity-badge activity-badge-thinking-active activity-badge-active";
        thinkingBadge.dataset.activityThinkingBadge = "1";

        const thinkingLabel = document.createTextNode(t.thinking);
        thinkingBadge.appendChild(thinkingLabel);

        const thinkingSeconds = document.createElement("span");
        thinkingSeconds.className = "activity-seconds activity-seconds-thinking";
        thinkingSeconds.dataset.activityThinkingSeconds = "1";
        thinkingSeconds.textContent = "0";
        thinkingBadge.appendChild(thinkingSeconds);

        badgesWrapper.appendChild(workingBadge);
        badgesWrapper.appendChild(thinkingBadge);

        // Reset activity state and start thinking timer
        this.resetActivityState();
        this.startThinkingTimer(container);

        return badgesWrapper;
    }

    private resetActivityState(): void {
        this.activityToolCallCount = 0;
        this.activityThinkingSeconds = 0;
        this.activityWorkingActive = false;
        this.stopWorkingTimeout();
    }

    private startThinkingTimer(container: HTMLElement): void {
        this.stopThinkingTimer();

        this.activityThinkingTimerId = setInterval(() => {
            this.tickThinkingTimer(container);
        }, 1000);
    }

    private stopThinkingTimer(): void {
        if (this.activityThinkingTimerId !== null) {
            clearInterval(this.activityThinkingTimerId);
            this.activityThinkingTimerId = null;
        }
    }

    private stopWorkingTimeout(): void {
        if (this.activityWorkingTimeoutId !== null) {
            clearTimeout(this.activityWorkingTimeoutId);
            this.activityWorkingTimeoutId = null;
        }
    }

    private tickThinkingTimer(container: HTMLElement): void {
        this.activityThinkingSeconds++;
        const thinkingSecondsEl = container.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');
        if (thinkingSecondsEl) {
            thinkingSecondsEl.textContent = String(this.activityThinkingSeconds);
        }
    }

    private onToolCall(container: HTMLElement): void {
        // Increment tool call count
        this.activityToolCallCount++;
        const workingCountEl = container.querySelector<HTMLElement>('[data-activity-working-count="1"]');
        if (workingCountEl) {
            workingCountEl.textContent = String(this.activityToolCallCount);
        }

        // Make Working badge active
        if (!this.activityWorkingActive) {
            this.activityWorkingActive = true;
            this.setWorkingBadgeActive(container, true);
        }

        // Reset/start the 2-second timeout
        this.stopWorkingTimeout();
        this.activityWorkingTimeoutId = setTimeout(() => {
            this.activityWorkingActive = false;
            this.setWorkingBadgeActive(container, false);
        }, 2000);
    }

    private setWorkingBadgeActive(container: HTMLElement, active: boolean): void {
        const workingBadge = container.querySelector<HTMLElement>('[data-activity-working-badge="1"]');
        const workingCountEl = container.querySelector<HTMLElement>('[data-activity-working-count="1"]');

        if (workingBadge) {
            if (active) {
                workingBadge.classList.remove("activity-badge-inactive");
                workingBadge.classList.add("activity-badge-working-active", "activity-badge-active");
            } else {
                workingBadge.classList.remove("activity-badge-working-active", "activity-badge-active");
                workingBadge.classList.add("activity-badge-inactive");
            }
        }
        if (workingCountEl) {
            if (active) {
                workingCountEl.classList.remove("activity-seconds-inactive");
                workingCountEl.classList.add("activity-seconds-working");
            } else {
                workingCountEl.classList.remove("activity-seconds-working");
                workingCountEl.classList.add("activity-seconds-inactive");
            }
        }
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
        if (!messagesList) {
            return;
        }

        const eventEl = this.renderTechnicalEvent(event);
        messagesList.appendChild(eventEl);

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
        const tr = this.translationsValue;

        switch (e.kind) {
            case "inference_start":
                wrap.textContent = `→ ${tr.askingAi}`;
                wrap.classList.add("text-amber-600/70", "dark:text-amber-400/70");
                break;
            case "inference_stop":
                wrap.textContent = `← ${tr.aiResponseReceived}`;
                wrap.classList.add("text-amber-600/70", "dark:text-amber-400/70");
                break;
            case "tool_calling":
                wrap.innerHTML = `▶ <span class="font-medium">${escapeHtml(e.toolName ?? "?")}</span>`;
                if (e.toolInputs && e.toolInputs.length > 0) {
                    const ul = document.createElement("ul");
                    ul.className = "mt-0.5 ml-3 list-disc space-y-0.5";
                    for (const ti of e.toolInputs) {
                        const li = document.createElement("li");
                        li.className = "text-[9px]";
                        const displayValue = ti.value.length > 50 ? ti.value.slice(0, 50) + "…" : ti.value;
                        li.textContent = `${ti.key}: ${displayValue}`;
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
                wrap.textContent = `✖ ${e.errorMessage ?? tr.unknownError}`;
                wrap.classList.add("text-red-600/70", "dark:text-red-400/70");
                break;
            case "build_start":
                wrap.textContent = "▶ Building workspace…";
                wrap.classList.add("text-blue-600/70", "dark:text-blue-400/70");
                break;
            case "build_complete": {
                const result = (e.toolResult ?? "").slice(0, 200);
                wrap.innerHTML = `◀ Build completed. ${escapeHtml(result)}${(e.toolResult?.length ?? 0) > 200 ? "…" : ""}`;
                wrap.classList.add("text-green-600/70", "dark:text-green-400/70");
                break;
            }
            case "build_error":
                wrap.textContent = `✖ Build failed: ${e.errorMessage ?? tr.unknownError}`;
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
        const header = container.querySelector<HTMLElement>('[data-header="1"]');

        if (!indicator) {
            return;
        }

        const animationState = getProgressAnimationState(event.kind);

        if (animationState.intensify) {
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
        } else if (animationState.returnToNormal) {
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
        }

        // Update activity indicators (Working/Thinking badges)
        this.updateActivityIndicators(container, event);

        // Note: agent_error events are not surfaced to the UI since the agent
        // can handle errors gracefully and continue working
    }

    private updateActivityIndicators(container: HTMLElement, event: AgentEvent): void {
        // Working badge tracks tool calls and post-agent build
        if (event.kind === "tool_calling" || event.kind === "build_start") {
            this.onToolCall(container);
        }
        if (event.kind === "build_complete" || event.kind === "build_error") {
            this.completeActivityIndicators(container);
        }
    }

    private completeActivityIndicators(container: HTMLElement): void {
        // Stop all timers
        this.stopThinkingTimer();
        this.stopWorkingTimeout();

        // Make both badges inactive (but keep them visible)
        this.setWorkingBadgeActive(container, false);
        this.setThinkingBadgeActive(container, false);
    }

    private setThinkingBadgeActive(container: HTMLElement, active: boolean): void {
        const thinkingBadge = container.querySelector<HTMLElement>('[data-activity-thinking-badge="1"]');
        const thinkingSecondsEl = container.querySelector<HTMLElement>('[data-activity-thinking-seconds="1"]');

        if (thinkingBadge) {
            if (active) {
                thinkingBadge.classList.remove("activity-badge-inactive");
                thinkingBadge.classList.add("activity-badge-thinking-active", "activity-badge-active");
            } else {
                thinkingBadge.classList.remove("activity-badge-thinking-active", "activity-badge-active");
                thinkingBadge.classList.add("activity-badge-inactive");
            }
        }
        if (thinkingSecondsEl) {
            if (active) {
                thinkingSecondsEl.classList.remove("activity-seconds-inactive");
                thinkingSecondsEl.classList.add("activity-seconds-thinking");
            } else {
                thinkingSecondsEl.classList.remove("activity-seconds-thinking");
                thinkingSecondsEl.classList.add("activity-seconds-inactive");
            }
        }
    }

    private markTechnicalContainerComplete(container: HTMLElement, cancelled: boolean = false): void {
        const technicalContainer = this.getTechnicalMessagesContainer(container);
        if (!technicalContainer) {
            return;
        }

        // Hide activity indicators (Working/Thinking badges)
        this.completeActivityIndicators(technicalContainer);

        const indicator = technicalContainer.querySelector<HTMLElement>('[data-indicator="1"]');
        const indicatorGlow = indicator?.nextElementSibling as HTMLElement | null;
        const label = technicalContainer.querySelector<HTMLElement>('[data-label="1"]');
        const header = technicalContainer.querySelector<HTMLElement>('[data-header="1"]');
        const sparkle = label?.previousElementSibling as HTMLElement | null;

        const style = cancelled ? getCancelledContainerStyle() : getCompletedContainerStyle();
        const workingStyle = getWorkingContainerStyle();

        // Stop all animations
        if (indicator) {
            indicator.classList.remove("animate-pulse", "scale-125");
        }
        if (indicatorGlow) {
            indicatorGlow.classList.add("hidden");
        }

        // Transition from working to completed state
        if (indicator) {
            // Remove working indicator classes
            indicator.classList.remove("bg-gradient-to-r", ...workingStyle.indicatorClass.split(" "));
            // Add completed indicator classes
            indicator.classList.add(...style.indicatorClass.split(" "));
        }
        if (label) {
            label.innerHTML = style.labelText;
            // Remove working label classes and add completed
            label.classList.remove(...workingStyle.labelColorClass.split(" "));
            label.classList.add(...style.labelColorClass.split(" "));
        }
        if (sparkle) {
            sparkle.textContent = style.sparkleEmoji;
        }
        if (header) {
            // Remove working header classes
            header.classList.remove(
                ...workingStyle.headerBgClass.split(" "),
                "hover:from-purple-100/80",
                "hover:to-blue-100/80",
                "dark:hover:from-purple-900/30",
                "dark:hover:to-blue-900/30",
            );
            // Add completed header classes
            header.classList.add(...style.headerBgClass.split(" "));
        }
    }

    private appendError(container: HTMLElement, message: string): void {
        const div = document.createElement("div");
        div.className = "text-red-600 dark:text-red-400 text-sm";
        div.textContent = `✖ ${message}`;
        container.appendChild(div);
        this.scrollToBottom();
    }

    /**
     * Handle remote asset insertion from remote-asset-browser controller.
     * Inserts the asset URL at the cursor position in the instruction textarea.
     */
    handleAssetInsert(event: CustomEvent<{ url: string }>): void {
        const url = event.detail?.url;
        if (!url || !this.hasInstructionTarget) {
            return;
        }

        const textarea = this.instructionTarget;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;

        textarea.value = textarea.value.slice(0, start) + url + textarea.value.slice(end);

        const newPos = start + url.length;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();
    }

    /**
     * Handle upload complete event from remote-asset-browser controller.
     * Prepends a system note to the instruction textarea to inform the agent.
     */
    handleUploadComplete(): void {
        if (!this.hasInstructionTarget) {
            return;
        }

        const systemNote = "[System Note: a new remote asset has been uploaded]\n\n";
        const textarea = this.instructionTarget;
        const currentValue = textarea.value;

        // Only prepend if not already present
        if (!currentValue.startsWith(systemNote)) {
            textarea.value = systemNote + currentValue;
        }
    }

    /**
     * Handle prompt suggestion insertion from prompt-suggestions controller.
     * Inserts the suggestion text at the cursor position in the instruction textarea.
     */
    handleSuggestionInsert(event: CustomEvent<{ text: string }>): void {
        const text = event.detail?.text;
        if (!text || !this.hasInstructionTarget) {
            return;
        }

        const textarea = this.instructionTarget;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;

        textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);

        const newPos = start + text.length;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();
    }
}
