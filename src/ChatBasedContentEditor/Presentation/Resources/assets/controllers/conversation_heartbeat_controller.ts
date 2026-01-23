import { Controller } from "@hotwired/stimulus";

/**
 * Stimulus controller for sending periodic heartbeats to track user presence.
 * Prevents stale conversation locks by updating the lastActivityAt timestamp.
 */
export default class extends Controller {
    static values = {
        heartbeatUrl: String,
        interval: { type: Number, default: 10000 }, // 10 seconds default
    };

    declare readonly heartbeatUrlValue: string;
    declare readonly intervalValue: number;

    private heartbeatIntervalId: ReturnType<typeof setInterval> | null = null;

    connect(): void {
        this.startHeartbeat();
    }

    disconnect(): void {
        this.stopHeartbeat();
    }

    private startHeartbeat(): void {
        // Send initial heartbeat immediately
        this.sendHeartbeat();
        // Then send periodically
        this.heartbeatIntervalId = setInterval(() => this.sendHeartbeat(), this.intervalValue);
    }

    private stopHeartbeat(): void {
        if (this.heartbeatIntervalId !== null) {
            clearInterval(this.heartbeatIntervalId);
            this.heartbeatIntervalId = null;
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
                    this.stopHeartbeat();
                }
            }
        } catch {
            // Network error - keep trying, might be temporary
        }
    }
}
