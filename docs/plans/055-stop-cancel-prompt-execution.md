# Implementation Plan: Stop/Cancel Prompt Execution (#55)

> **Issue:** [#55 — Prompt execution cannot be stopped and may permanently lock the workspace](https://github.com/dx-tooling/sitebuilder-webapp/issues/55)

## Problem Summary

When an agent prompt runs longer than expected or behaves incorrectly, the user has no way to stop it. If they leave the session while the agent is still running, the workspace becomes permanently locked (`IN_CONVERSATION`) because the `EditSession` never transitions out of `Running`, and the conversation stays `ONGOING`.

**Impact:** Loss of session state, wasted LLM context quota, and a workspace that requires the stale-conversation timeout (currently only triggered when the project list is loaded) to recover — if it recovers at all.

---

## Root Causes

1. **No cancellation mechanism** — `EditSessionStatus` has four states (`Pending → Running → Completed | Failed`) but no `Cancelled` state. The `RunEditSessionHandler` iterates the streaming generator to completion with no way to break out early.

2. **No cancel API endpoint** — There is no backend route the frontend can call to request cancellation of a running `EditSession`.

3. **No cancel UI** — The submit button switches to a disabled "Making changes" state during execution, but there is no Stop/Cancel button.

4. **Stale conversation cleanup is passive** — `ChatBasedContentEditorFacade::releaseStaleConversations()` runs only when the project list page is loaded (`ProjectController::list()`), not on a schedule. If nobody visits the project list, the lock persists indefinitely.

5. **Handler cannot be signalled** — The Symfony Messenger handler (`RunEditSessionHandler`) runs synchronously within a worker process. PHP has no built-in async cancellation; the handler must cooperatively check a cancellation flag.

---

## Solution Design

### Overview

Introduce a **cooperative cancellation** mechanism: the frontend calls a new cancel endpoint that sets the `EditSession` status to `Cancelling`. The message handler checks this flag at every iteration of the streaming loop and breaks out early when it detects cancellation. A final status of `Cancelled` is set, the session's `done` chunk is written, and the workspace is left in a usable state.

Additionally, improve the **stale conversation recovery** to run automatically rather than only on user-triggered page loads.

### Architecture Principles

- All changes respect the vertical architecture: `ChatBasedContentEditor` owns conversation/session lifecycle; `LlmContentEditor` owns the agent; `WorkspaceMgmt` owns workspace state transitions.
- Cross-vertical communication happens via facades and DTOs only.
- Frontend changes stay inside the `ChatBasedContentEditor` vertical's Stimulus controllers and Twig templates.

---

## Implementation Steps

### Step 1: Add `Cancelling` and `Cancelled` states to `EditSessionStatus`

**File:** `src/ChatBasedContentEditor/Domain/Enum/EditSessionStatus.php`

Add two new enum cases:

```php
enum EditSessionStatus: string
{
    case Pending    = 'pending';
    case Running    = 'running';
    case Cancelling = 'cancelling';   // ← NEW: cancel requested, handler should stop
    case Completed  = 'completed';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';    // ← NEW: handler acknowledged and stopped
}
```

**Rationale:** Two states are needed because cancellation is cooperative. `Cancelling` is the transient signal; `Cancelled` is the terminal state. This avoids race conditions where the handler finishes normally between the cancel request and the status check.

**Migration:** Create a Doctrine migration to widen the `status` column's allowed values (the column is `VARCHAR(32)` with the enum type, so no schema change is needed, but a migration should document the new values).

---

### Step 2: Add a `cancel` API endpoint

**File:** `src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php`

Add a new route:

```
POST /chat-based-content-editor/cancel/{sessionId}
```

**Logic:**
1. Validate CSRF token.
2. Look up the `EditSession` by ID.
3. Verify the authenticated user owns the conversation.
4. Verify the session is in `Pending` or `Running` state.
5. Set the session status to `Cancelling`.
6. Flush.
7. Return `{ "success": true }`.

If the session is already in a terminal state (`Completed`, `Failed`, `Cancelled`), return `{ "success": true, "alreadyFinished": true }` — the frontend should handle this gracefully.

**CSRF:** Use a dedicated token name `chat_based_content_editor_cancel` (or reuse the existing `chat_based_content_editor_run` token that is already present on the form).

---

### Step 3: Make the handler check for cancellation cooperatively

**File:** `src/ChatBasedContentEditor/Infrastructure/Handler/RunEditSessionHandler.php`

Inside the `foreach ($generator as $chunk)` loop, add a periodic cancellation check:

```php
foreach ($generator as $chunk) {
    // Cooperative cancellation check
    $this->entityManager->refresh($session);
    if ($session->getStatus() === EditSessionStatus::Cancelling) {
        EditSessionChunk::createDoneChunk($session, false, 'Cancelled by user.');
        $session->setStatus(EditSessionStatus::Cancelled);
        $this->entityManager->flush();
        return;
    }

    // ... existing chunk processing ...
}
```

**Important considerations:**

- **`$this->entityManager->refresh($session)`** is necessary because the handler runs in a long-lived Messenger worker process. Without a refresh, the entity manager's identity map would return the stale `Running` status cached at the start of the handler invocation. The `refresh()` call issues a `SELECT` to the database and updates the in-memory entity to reflect the current `Cancelling` status that was set by the cancel API endpoint in a separate HTTP request.

- **Performance:** The refresh adds one `SELECT` query per generator iteration. Given that each iteration corresponds to an LLM streaming chunk (which involves network I/O on the order of hundreds of milliseconds), the overhead of a simple primary-key SELECT is negligible.

- **Atomicity:** After detecting `Cancelling`, write a `done` chunk with `success=false` and message `"Cancelled by user."`, then set the terminal status to `Cancelled`. This ensures the frontend's polling loop receives a proper `done` chunk and stops.

- **Also handle `Pending` → `Cancelling`:** If the cancel arrives before the handler even picks up the message, the handler should check the status at the very start (after fetching the session) and immediately bail:

```php
if ($session->getStatus() === EditSessionStatus::Cancelling) {
    $session->setStatus(EditSessionStatus::Cancelled);
    EditSessionChunk::createDoneChunk($session, false, 'Cancelled before execution started.');
    $this->entityManager->flush();
    return;
}
```

---

### Step 4: Handle cancellation in the frontend polling loop

**File:** `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts`

The existing polling loop already handles the `done` chunk type and checks for `completed`/`failed` status. Extend it to also recognize `cancelled`:

```typescript
if (data.status === "completed" || data.status === "failed" || data.status === "cancelled") {
    this.stopPolling();
    this.resetSubmitButton();
    return;
}
```

The `done` chunk with `"Cancelled by user."` will be rendered as a normal completion message, so no special rendering logic is needed.

---

### Step 5: Add a "Stop" button to the UI

**Files:**
- `src/ChatBasedContentEditor/Presentation/Resources/templates/chat_based_content_editor.twig`
- `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts`

**Twig template changes:**

Add a hidden "Stop" button next to the submit button, wrapped in a container that can be toggled:

```html
<button type="button"
        data-chat-based-content-editor-target="cancelButton"
        data-action="chat-based-content-editor#handleCancel"
        class="hidden px-4 py-2 rounded-md bg-red-600 text-white text-sm font-medium
               hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500
               focus:ring-offset-2 dark:focus:ring-offset-dark-900">
    Stop
</button>
```

Pass the cancel URL template as a Stimulus value:

```
cancelUrlTemplate: '/chat-based-content-editor/cancel/__SESSION_ID__'
```

**Stimulus controller changes:**

1. Add `cancelButton` to `static targets`.
2. Add `cancelUrlTemplate` to `static values`.
3. In `setWorkingState()`, show the cancel button and hide the submit button.
4. In `resetSubmitButton()`, hide the cancel button and show the submit button.
5. Add a `handleCancel()` action:

```typescript
async handleCancel(): Promise<void> {
    if (!this.currentPollingState) return;

    const cancelUrl = this.cancelUrlTemplateValue
        .replace('__SESSION_ID__', this.currentPollingState.sessionId);

    // Disable the cancel button immediately to prevent double-clicks
    this.cancelButtonTarget.disabled = true;
    this.cancelButtonTarget.textContent = 'Stopping…';

    try {
        const csrfInput = document.querySelector(
            'input[name="_csrf_token"]'
        ) as HTMLInputElement | null;

        const formData = new FormData();
        if (csrfInput) {
            formData.append('_csrf_token', csrfInput.value);
        }

        await fetch(cancelUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        });

        // Don't stop polling here — let the polling loop detect the
        // `cancelled` status and `done` chunk naturally. This ensures
        // all chunks produced before cancellation are still displayed.
    } catch {
        // If the cancel request fails, re-enable the button
        this.cancelButtonTarget.disabled = false;
        this.cancelButtonTarget.textContent = 'Stop';
    }
}
```

**Key UX decision:** The cancel button does NOT immediately stop polling. Instead, polling continues until the handler writes the `done` chunk and sets status to `Cancelled`. This guarantees the user sees all agent output produced before cancellation (partial work, tool call results, etc.).

---

### Step 6: Handle page resume with cancelled sessions

**File:** `src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php` (method `show()`)

The `show()` action currently detects active sessions (status `Pending` or `Running`) and passes them to the frontend for polling resumption. Add `Cancelling` to this check:

```php
if (
    $sessionStatus === EditSessionStatus::Pending
    || $sessionStatus === EditSessionStatus::Running
    || $sessionStatus === EditSessionStatus::Cancelling
) {
    $activeSession = $session;
    // ... collect chunks ...
}
```

This ensures that if the user refreshes the page while cancellation is in progress, the frontend picks up the session and shows the cancel-in-progress state.

---

### Step 7: Extend poll response to include `cancelling` status

**File:** `src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php` (method `poll()`)

No change needed — the poll response already returns `$session->getStatus()->value`, which will now include `"cancelling"` and `"cancelled"` automatically.

In the frontend, optionally show a visual indicator when the poll returns status `"cancelling"`:

```typescript
if (data.status === "cancelling") {
    // Update UI to show "Cancelling…" state on the technical container
}
```

---

### Step 8: Improve stale conversation recovery

Currently, `releaseStaleConversations()` only runs when the project list page is loaded. This is insufficient.

#### 8a: Add a Symfony console command

**File:** `src/ChatBasedContentEditor/Infrastructure/Command/ReleaseStaleConversationsCommand.php`

```php
#[AsCommand(
    name: 'app:release-stale-conversations',
    description: 'Release conversations that have been inactive for too long'
)]
final class ReleaseStaleConversationsCommand extends Command
{
    public function __construct(
        private readonly ChatBasedContentEditorFacadeInterface $facade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout in minutes', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeout = (int) $input->getOption('timeout');
        $released = $this->facade->releaseStaleConversations($timeout);

        $output->writeln(sprintf('Released %d stale workspace(s).', count($released)));

        return Command::SUCCESS;
    }
}
```

#### 8b: Schedule via Symfony Scheduler or cron

Add the command to the project's scheduler or cron configuration to run every 2 minutes:

```
*/2 * * * * cd /var/www && php bin/console app:release-stale-conversations --timeout=5
```

Alternatively, if the project uses Symfony Scheduler, register a `RecurringMessage` for this.

#### 8c: Also release stale sessions stuck in `Running`

Add a new method to `ChatBasedContentEditorFacade` that finds `EditSession` records that have been `Running` for longer than a threshold (e.g. 30 minutes) and marks them as `Failed`. This handles the edge case where the Messenger worker crashes mid-execution and the session is never finalized.

---

### Step 9: Handle `Cancelling` sessions stuck in handler

If the handler crashes after detecting `Cancelling` but before writing the `Cancelled` status, the session could be stuck in `Cancelling` indefinitely. The stale recovery command (Step 8) should also handle this:

- Sessions in `Cancelling` state for more than 2 minutes should be transitioned to `Cancelled` with a `done` chunk.
- Sessions in `Running` state for more than 30 minutes should be transitioned to `Failed` with a `done` chunk.

---

### Step 10: Translations

**File:** `translations/messages.en.yaml` (or equivalent)

Add translation keys:

```yaml
editor.stop: "Stop"
editor.stopping: "Stopping…"
editor.cancelled: "Cancelled by user."
```

Pass these through the existing `translationsValue` mechanism in the Twig template.

---

## File Change Summary

| File | Change |
|------|--------|
| `src/ChatBasedContentEditor/Domain/Enum/EditSessionStatus.php` | Add `Cancelling` and `Cancelled` cases |
| `src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php` | Add `cancel()` route; update `show()` to include `Cancelling` sessions as active |
| `src/ChatBasedContentEditor/Infrastructure/Handler/RunEditSessionHandler.php` | Add cooperative cancellation check in the streaming loop; check status at start |
| `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts` | Add cancel button target/action, `handleCancel()`, update polling for `cancelled` status |
| `src/ChatBasedContentEditor/Presentation/Resources/templates/chat_based_content_editor.twig` | Add hidden Stop button, pass `cancelUrlTemplate` value |
| `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_helpers.ts` | Add `"cancelling"` and `"cancelled"` to status type if needed |
| `src/ChatBasedContentEditor/Facade/ChatBasedContentEditorFacade.php` | Add stale `Running`/`Cancelling` session recovery |
| `src/ChatBasedContentEditor/Facade/ChatBasedContentEditorFacadeInterface.php` | Add method signature for stale session recovery |
| `src/ChatBasedContentEditor/Infrastructure/Command/ReleaseStaleConversationsCommand.php` | **NEW** — Console command for scheduled stale recovery |
| `translations/messages.en.yaml` | Add translation keys for Stop/Cancel UI |
| `migrations/VersionXXXX.php` | Document new enum values (no schema change needed) |

---

## Testing Plan

### Unit Tests

1. **`EditSessionStatus` enum** — Verify the new cases exist and serialize correctly.
2. **`RunEditSessionHandler`** — Mock the entity manager to return a session in `Cancelling` state mid-loop; verify the handler breaks out, writes a `done` chunk, and sets `Cancelled`.
3. **`RunEditSessionHandler` pre-start check** — Mock a session that is already `Cancelling` when the handler starts; verify immediate bail-out.
4. **Cancel endpoint** — Test the controller action with valid/invalid session states, CSRF validation, and authorization.
5. **Stale conversation cleanup** — Verify that `Running` sessions older than the threshold are transitioned to `Failed`.
6. **Stale conversation cleanup** — Verify that `Cancelling` sessions older than the threshold are transitioned to `Cancelled`.

### Frontend Tests (Vitest)

1. **Polling loop** — Verify that `cancelled` status stops polling.
2. **Cancel button** — Verify `handleCancel()` calls the cancel endpoint and disables the button.
3. **UI state transitions** — Verify the cancel button appears during execution and disappears on completion/cancellation.

### Manual Testing

1. Start a prompt → click Stop → verify agent stops within a few seconds and workspace remains usable.
2. Start a prompt → navigate away → return → verify session shows as cancelled (or eventually cleaned up by stale recovery).
3. Start a prompt → let it complete normally → verify no regressions in the happy path.
4. Start a prompt → click Stop before the handler picks it up → verify the session is cancelled without executing.
5. Verify the `app:release-stale-conversations` command works correctly.

---

## Sequence Diagram: Cancel Flow

```
User          Frontend (Stimulus)      Backend (Controller)       Messenger Worker (Handler)      Database
 │                 │                         │                            │                          │
 │  click Stop     │                         │                            │                          │
 │────────────────>│                         │                            │                          │
 │                 │  POST /cancel/{id}      │                            │                          │
 │                 │────────────────────────>│                            │                          │
 │                 │                         │  SET status=Cancelling     │                          │
 │                 │                         │───────────────────────────────────────────────────────>│
 │                 │                         │  200 OK                    │                          │
 │                 │<────────────────────────│                            │                          │
 │                 │                         │                            │                          │
 │                 │  (polling continues)    │                            │  refresh() → reads       │
 │                 │                         │                            │  Cancelling               │
 │                 │                         │                            │<─────────────────────────│
 │                 │                         │                            │                          │
 │                 │                         │                            │  write done chunk +       │
 │                 │                         │                            │  SET status=Cancelled     │
 │                 │                         │                            │─────────────────────────>│
 │                 │                         │                            │                          │
 │                 │  GET /poll/{id}         │                            │                          │
 │                 │────────────────────────>│  reads Cancelled + done    │                          │
 │                 │                         │<─────────────────────────────────────────────────────│
 │                 │  { status: cancelled,   │                            │                          │
 │                 │    chunks: [done] }     │                            │                          │
 │                 │<────────────────────────│                            │                          │
 │                 │                         │                            │                          │
 │                 │  stop polling,          │                            │                          │
 │                 │  reset UI               │                            │                          │
 │<────────────────│                         │                            │                          │
```

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| `entityManager->refresh()` on every chunk iteration could add latency | One primary-key SELECT per iteration is negligible vs. LLM network latency. Benchmark if needed. |
| Handler crashes between detecting `Cancelling` and writing `Cancelled` | Stale recovery command (Step 8/9) catches stuck `Cancelling` sessions after 2 minutes. |
| Race condition: handler finishes normally just as cancel is requested | The cancel endpoint checks for terminal states and returns `alreadyFinished: true`. Frontend handles gracefully. |
| Messenger worker restart during cancellation | Same as crash scenario — stale recovery handles it. |
| Cancel button clicked multiple times | Button is disabled on first click. Endpoint is idempotent (setting `Cancelling` on an already-`Cancelling` session is a no-op). |
