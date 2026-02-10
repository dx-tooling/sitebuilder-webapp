# Implementation Plan: Fix AI budget bar undercounting (#77)

> **Issue:** [#77 — AI budget bar undercounts consumed context (tool calls)](https://github.com/dx-tooling/sitebuilder-webapp/issues/77)

## Problem

The budget bar showed low usage even when the in-memory context was full (e.g. before the infinite loop in #75). Cause: event chunks store truncated payloads (tool result → 200 chars); the service used `SUM(LENGTH(payloadJson))` for event bytes, so full tool results were undercounted.

## Solution

Persist the **actual byte length** of tool inputs and results per event chunk; use that in the context-usage sum.

- **AgentEventDto**: Added `inputBytes`, `resultBytes` (optional).
- **AgentEventCollectingObserver**: Sets them when building tool_calling / tool_called DTOs.
- **EditSessionChunk**: New nullable column `context_bytes`; `createEventChunk(..., $contextBytes)`.
- **RunEditSessionHandler**: Passes `(inputBytes ?? 0) + (resultBytes ?? 0)` when creating event chunks.
- **ConversationContextUsageService**: Uses `SUM(COALESCE(ch.contextBytes, LENGTH(ch.payloadJson)))` for event chunks (fallback for legacy rows).

No frontend or API changes; the budget bar simply becomes accurate.
