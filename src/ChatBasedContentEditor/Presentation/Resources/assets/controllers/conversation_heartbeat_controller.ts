import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for sending periodic heartbeats to track user presence.
 * Prevents stale conversation locks by updating the lastActivityAt timestamp.
 *
 * Uses non-overlapping polling: next heartbeat is scheduled only after the
 * current one completes, preventing request pile-up on slow connections.
 */
export default class extends Controller {
    static values = {
        heartbeatUrl: String,
        interval: { type: Number, default: 10000 }, // 10 seconds default
    };

    declare readonly heartbeatUrlValue: string;
    declare readonly intervalValue: number;

    private heartbeatTimeoutId: ReturnType<typeof setTimeout> | null = null;
    private isActive: boolean = false;

    connect(): void {
        this.isActive = true;
        this.sendHeartbeat();
    }

    disconnect(): void {
        this.isActive = false;
        this.stopHeartbeat();
    }

    private stopHeartbeat(): void {
        if (this.heartbeatTimeoutId !== null) {
            clearTimeout(this.heartbeatTimeoutId);
            this.heartbeatTimeoutId = null;
        }
    }

    private scheduleNextHeartbeat(): void {
        if (this.isActive) {
            this.heartbeatTimeoutId = setTimeout(() => this.sendHeartbeat(), this.intervalValue);
        }
    }

    private async sendHeartbeat(): Promise<void> {
        try {
            const response = await fetch(this.heartbeatUrlValue, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            if (!response.ok) {
                // If the conversation is no longer accessible, stop heartbeating
                // This could happen if the conversation was finished or the user was logged out
                if (response.status === 403 || response.status === 404 || response.status === 400) {
                    this.isActive = false;

                    return;
                }
            }
        } catch {
            // Network error - keep trying, might be temporary
        }

        this.scheduleNextHeartbeat();
    }
}
