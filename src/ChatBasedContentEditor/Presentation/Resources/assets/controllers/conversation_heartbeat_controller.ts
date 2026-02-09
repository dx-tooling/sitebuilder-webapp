import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for sending periodic heartbeats to track user presence.
 * Prevents stale conversation locks by updating the lastActivityAt timestamp.
 *
 * - Heartbeats are sent only when the tab is visible, so switching to the project
 *   page (or another tab) stops updates and the session can auto-end after timeout.
 * - Countdown runs to 0 and only resets when the user clicks "Continue" or returns to the tab.
 *   Automatic heartbeats do NOT reset the countdown, so the session ends when countdown hits 0.
 * - While the agent is working, the countdown is reset to full and frozen; it resumes when the agent is done.
 * - Typing in the instruction field resets the countdown.
 *
 * Uses non-overlapping polling: next heartbeat is scheduled only after the
 * current one completes, preventing request pile-up on slow connections.
 */
export default class extends Controller {
    static values = {
        heartbeatUrl: String,
        interval: { type: Number, default: 10000 }, // 10 seconds
        sessionTimeoutMinutes: { type: Number, default: 5 },
        translations: Object,
        finishUrl: String,
        finishCsrfToken: String,
        projectListUrl: String,
    };

    static targets = ["countdown"];

    declare readonly heartbeatUrlValue: string;
    declare readonly intervalValue: number;
    declare readonly sessionTimeoutMinutesValue: number;
    declare readonly translationsValue: { endsIn: string; continue: string };
    declare readonly finishUrlValue: string;
    declare readonly finishCsrfTokenValue: string;
    declare readonly projectListUrlValue: string;
    declare readonly countdownTarget: HTMLElement | undefined;

    private heartbeatTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private countdownTickId: ReturnType<typeof setInterval> | null = null;
    private countdownSeconds: number = 0;
    private isActive: boolean = false;
    private sessionEnded: boolean = false;
    private visibilityListener: (() => void) | null = null;
    private agentWorkStartedListener: (() => void) | null = null;
    private agentWorkFinishedListener: (() => void) | null = null;
    private userTypedListener: (() => void) | null = null;

    connect(): void {
        this.isActive = true;
        this.countdownSeconds = this.sessionTimeoutMinutesValue * 60;
        this.updateCountdownDisplay();
        this.startCountdownTick();
        this.visibilityListener = () => this.handleVisibilityChange();
        document.addEventListener("visibilitychange", this.visibilityListener);

        this.agentWorkStartedListener = () => this.onAgentWorkStarted();
        this.agentWorkFinishedListener = () => this.onAgentWorkFinished();
        document.addEventListener("chat-based-content-editor:agent-work-started", this.agentWorkStartedListener);
        document.addEventListener("chat-based-content-editor:agent-work-finished", this.agentWorkFinishedListener);

        this.userTypedListener = () => this.resetCountdown();
        document.addEventListener("chat-based-content-editor:user-typed", this.userTypedListener);

        if (document.visibilityState === "visible") {
            this.sendHeartbeat();
        }
    }

    disconnect(): void {
        this.isActive = false;
        this.stopHeartbeat();
        this.stopCountdownTick();
        if (this.visibilityListener !== null) {
            document.removeEventListener("visibilitychange", this.visibilityListener);
        }
        if (this.agentWorkStartedListener !== null) {
            document.removeEventListener("chat-based-content-editor:agent-work-started", this.agentWorkStartedListener);
        }
        if (this.agentWorkFinishedListener !== null) {
            document.removeEventListener(
                "chat-based-content-editor:agent-work-finished",
                this.agentWorkFinishedListener,
            );
        }
        if (this.userTypedListener !== null) {
            document.removeEventListener("chat-based-content-editor:user-typed", this.userTypedListener);
        }
    }

    /**
     * Called when the user clicks "Continue" to extend the session (sends heartbeat and resets countdown).
     */
    continueClicked(): void {
        if (document.visibilityState === "visible") {
            this.resetCountdown();
            this.sendHeartbeat();
        }
    }

    private handleVisibilityChange(): void {
        if (document.visibilityState === "visible") {
            this.resetCountdown();
            this.sendHeartbeat();
        } else {
            this.stopHeartbeat();
        }
    }

    private onAgentWorkStarted(): void {
        this.resetCountdown();
        this.stopCountdownTick();
    }

    private onAgentWorkFinished(): void {
        this.startCountdownTick();
    }

    private stopHeartbeat(): void {
        if (this.heartbeatTimeoutId !== null) {
            clearTimeout(this.heartbeatTimeoutId);
            this.heartbeatTimeoutId = null;
        }
    }

    private scheduleNextHeartbeat(): void {
        if (this.isActive && !this.sessionEnded && document.visibilityState === "visible") {
            this.heartbeatTimeoutId = setTimeout(() => this.sendHeartbeat(), this.intervalValue);
        }
    }

    private resetCountdown(): void {
        this.countdownSeconds = this.sessionTimeoutMinutesValue * 60;
        this.updateCountdownDisplay();
    }

    private startCountdownTick(): void {
        this.stopCountdownTick();
        this.countdownTickId = setInterval(() => {
            if (this.countdownSeconds > 0) {
                this.countdownSeconds -= 1;
                this.updateCountdownDisplay();
            } else if (!this.sessionEnded) {
                this.endSessionDueToTimeout();
            }
        }, 1000);
    }

    private stopCountdownTick(): void {
        if (this.countdownTickId !== null) {
            clearInterval(this.countdownTickId);
            this.countdownTickId = null;
        }
    }

    private updateCountdownDisplay(): void {
        if (this.countdownTarget == null) {
            return;
        }
        const m = Math.floor(this.countdownSeconds / 60);
        const s = this.countdownSeconds % 60;
        const text = `${this.translationsValue.endsIn} ${m}:${s.toString().padStart(2, "0")}`;
        this.countdownTarget.textContent = text;
    }

    /**
     * When countdown reaches 0: finish the session on the server and redirect to project list.
     */
    private async endSessionDueToTimeout(): Promise<void> {
        if (this.sessionEnded) {
            return;
        }
        this.sessionEnded = true;
        this.stopHeartbeat();
        this.stopCountdownTick();
        this.isActive = false;

        try {
            const formData = new FormData();
            formData.append("_csrf_token", this.finishCsrfTokenValue);
            await fetch(this.finishUrlValue, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
        } catch {
            // Continue to redirect even if request failed
        }
        window.location.href = this.projectListUrlValue;
    }

    private async sendHeartbeat(): Promise<void> {
        if (this.sessionEnded) {
            return;
        }
        try {
            const response = await fetch(this.heartbeatUrlValue, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (!response.ok) {
                if (response.status === 403 || response.status === 404 || response.status === 400) {
                    this.isActive = false;
                    return;
                }
            }
            // Do NOT reset countdown on automatic heartbeat – only "Continue" and tab focus reset it
        } catch {
            // Network error - keep trying, might be temporary
        }

        this.scheduleNextHeartbeat();
    }
}
