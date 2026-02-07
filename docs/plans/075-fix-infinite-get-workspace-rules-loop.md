# Implementation Plan: Fix infinite get_workspace_rules tool-call loop (#75)

> **Issue:** [#75 — Agent enters infinite get_workspace_rules tool-call loop when context window is exceeded](https://github.com/dx-tooling/sitebuilder-webapp/issues/75)

## Problem Summary

The AI content editor agent can enter an infinite loop where it repeatedly calls `get_workspace_rules` indefinitely, burning API credits without making progress. This happens when the in-memory chat history exceeds the 50,000-token context window during a single agent turn.

**Impact:** Unbounded API cost, wasted compute, and no useful work done for the user. Observed in production conversation `019c3759-4911-7974-87e2-b2535896279c` (~90 identical requests before the process was killed).

---

## Root Cause Chain

1. **History grows large**: During a turn, the agent accumulates a `UserMessage` plus many `ToolCallMessage`/`ToolCallResultMessage` pairs (file reads, folder listings, etc.). In the observed case, the request payload grew to ~228 KB.

2. **Aggressive trimming**: `AbstractChatHistory::trimHistory()` (NeuronAI vendor library) uses a binary search to remove messages from the **front** of the history. Since the `UserMessage` is at position 0, it is the first message to be trimmed away.

3. **Sequence validation clears everything**: After trimming, `ensureValidMessageSequence()` runs. Its `ensureStartsWithUser()` method looks for a `USER` or `DEVELOPER` role message. When none remain, it clears the entire history to `[]`.

4. **Empty history → system-prompt-only requests**: `HandleStream::stream()` calls the provider with `getMessages()` returning `[]`. The OpenAI provider prepends the system prompt, so the API receives only the system message.

5. **Model follows system prompt literally**: The system prompt says "You MUST call `get_workspace_rules` ONCE at the very beginning of the session". The model believes it is starting a new session and calls `get_workspace_rules`.

6. **Recursive stream perpetuates the loop**: The tool result is added to the empty history. `ensureValidMessageSequence()` immediately drops the tool-only messages, leaving history empty again. The cycle repeats indefinitely.

### Contributing factor: no circuit breaker

`BaseCodingAgent.executeSingleTool()` overrides the parent from NeuronAI without calling `parent::executeSingleTool()`, bypassing the `toolAttempts` counter and `ToolMaxTriesException` safety net.

---

## Solution Design

### Fix 1: Preserve UserMessage during context-window trimming (primary)

**File:** `src/LlmContentEditor/Infrastructure/ChatHistory/CallbackChatHistory.php`

Override `trimHistory()` to ensure the latest `UserMessage` is never lost:

- Track the most recent `UserMessage` (excluding `ToolCallResultMessage`, which extends `UserMessage` in NeuronAI) via the `onNewMessage()` hook
- Override `trimHistory()`: call `parent::trimHistory()`, then if `$this->history` is empty and a `UserMessage` was tracked, restore it as `[$latestUserMessage]`

This is safe because:
- Normal trimming (under the token limit) is unaffected
- We only intervene when the parent's trim+validation results in a completely empty history
- A single `UserMessage` is always a valid message sequence (starts with USER role)

### Fix 2: Restore tool-attempt tracking in BaseCodingAgent (secondary safety net)

**File:** `vendor/enterprise-tooling-for-symfony/coding-agent/src/Agent/BaseCodingAgent.php`

Add tool-attempt tracking that mirrors the parent logic:
- Increment `$this->toolAttempts[$tool->getName()]` before executing
- Check against `$tool->getMaxTries() ?? $this->toolMaxTries`
- Throw `ToolMaxTriesException` when exceeded (do **not** swallow it)
- Let `ToolMaxTriesException` propagate — it must not be caught by the error-as-result handler

### Fix 3: Unit tests

**File:** `tests/Unit/LlmContentEditor/ChatHistory/CallbackChatHistoryTest.php`

Test cases:
- Normal operation: messages under context window are not trimmed
- Aggressive trimming: when tool results push history past the context window, the latest `UserMessage` is preserved
- UserMessage tracking correctly ignores `ToolCallResultMessage`
- Callback is still invoked for new messages after trim recovery

---

## Architecture Notes

- All application-level changes are within the `LlmContentEditor` vertical (Infrastructure layer)
- The `BaseCodingAgent` change is in a vendor package owned by the same team
- No cross-vertical boundaries are crossed
- No database, API, or frontend changes required
