# Conversation Book

How the chat-based content editing conversation system works end-to-end: entity model, process lifecycle, async execution, streaming, cancellation, and stale-session recovery.

---

## 1. Terminology

Two levels of "session" exist in the codebase. The naming can be confusing, so this section pins down what each term means.

| Term | Domain Entity | Scope | Status Enum |
|------|---------------|-------|-------------|
| **Conversation** | `Conversation` | An entire chat between one user and the agent for one workspace. Contains many turns. | `ONGOING`, `FINISHED` |
| **EditSession** | `EditSession` | A single turn: one user instruction in, one agent response out. Lives inside a Conversation. | `Pending`, `Running`, `Cancelling`, `Completed`, `Failed`, `Cancelled` |
| **EditSessionChunk** | `EditSessionChunk` | One streaming fragment produced by the agent during an EditSession. | (no status — immutable) |
| **ConversationMessage** | `ConversationMessage` | A persisted message in the conversation history (user, assistant, or assistant_note_to_self for assistant note-to-self). Used for multi-turn LLM context. | (no status — immutable) |

The UI label "End session" means ending the **Conversation**. The "Stop" button cancels the current **EditSession**.

---

## 2. Entity Relationships

```
Project (1) ──── (1) Workspace
                       │
                       │ workspaceId (FK stored as GUID)
                       ▼
                  Conversation (many per workspace, over time)
                       │
                       ├──── EditSession (many per conversation)
                       │         │
                       │         └──── EditSessionChunk (many per edit session)
                       │                 • Text   – LLM output fragment
                       │                 • Event  – tool call / inference lifecycle
                       │                 • Done   – terminal chunk (success or error)
                       │
                       └──── ConversationMessage (many per conversation)
                                 • user, assistant, assistant_note_to_self (see section 4.4)
                                 • ordered by sequence number
                                 • fed back to the LLM as history on follow-up turns
```

Key points:
- A Workspace has at most **one** `ONGOING` Conversation at a time. This is the workspace lock.
- A Conversation can have **many** EditSessions, representing successive user prompts within the same chat.
- ConversationMessages accumulate across all EditSessions and form the LLM's conversation history.

---

## 3. Conversation Lifecycle

### 3.1 Starting a Conversation

**Entry:** `ChatBasedContentEditorController::startConversation()` — `GET /projects/{projectId}/conversation`

1. If the workspace needs setup, dispatch async setup and show a waiting page.
2. Once the workspace is `AVAILABLE_FOR_CONVERSATION`, call `ConversationService::startOrResumeConversation()`.
3. If an ongoing Conversation already exists for this user + workspace, return it (idempotent resume).
4. Otherwise, create a new Conversation, transition the workspace to `IN_CONVERSATION`, and redirect to the editor.

### 3.2 Within a Conversation

The user is now on the chat editor page (`ChatBasedContentEditorController::show()`). From here, they can:

- **Send prompts** — creates EditSessions (see section 4)
- **End session** — `ConversationService::finishConversation()`: commits pending changes, marks the Conversation `FINISHED`, transitions workspace to `AVAILABLE_FOR_CONVERSATION`
- **Send for review** — `ConversationService::sendToReview()`: commits, marks `FINISHED`, creates a GitHub PR, transitions workspace to `IN_REVIEW`

### 3.3 Conversation Status Transitions

```
ONGOING ──── finishConversation() ───→ FINISHED
   │                                       (workspace → AVAILABLE_FOR_CONVERSATION)
   │
   └──── sendToReview() ─────────────→ FINISHED
                                           (workspace → IN_REVIEW)
```

An `ONGOING` Conversation can also be released by the stale-conversation cleanup (see section 7).

---

## 4. EditSession Lifecycle (One Prompt Execution)

### 4.1 Dispatch

**Entry:** `ChatBasedContentEditorController::run()` — `POST /chat-based-content-editor/run`

1. Controller validates CSRF, ownership, and that the Conversation is `ONGOING`.
2. Creates an `EditSession` entity with status `Pending` and the user's instruction text.
3. Dispatches `RunEditSessionMessage` via Symfony Messenger (async, immediate transport).
4. Returns the `sessionId` to the frontend.

The frontend immediately starts polling for chunks (see section 5).

### 4.2 Execution

**Handler:** `RunEditSessionHandler::__invoke()`

The handler runs in the Messenger worker process:

1. **Pre-start check:** If the status is already `Cancelling` (user cancelled before the worker picked it up), write a `Done` chunk and set status to `Cancelled`. Return immediately.
2. Set status to `Running`. Flush.
3. Load the conversation's previous messages for multi-turn context.
4. Set the execution context (workspace path, project config, Docker image).
5. Build agent configuration from project settings (background/step/output instructions). The working folder path (`/workspace`) is included in the system prompt so it survives context-window trimming (see [#79](https://github.com/dx-tooling/sitebuilder-webapp/issues/79)).
6. Call `LlmContentEditorFacade::streamEditWithHistory()` which returns a PHP `Generator`.
7. Iterate the generator. For each yielded chunk:
   - **Cooperative cancellation check:** a lightweight DBAL query reads just the `status` column from the database (see section 6.2 for why). If `Cancelling`, repair the conversation history with a synthetic assistant message and stop (see section 6.3 for the full rationale), write a `Done` chunk, set `Cancelled`, and return.
   - **Text chunk:** persist as `EditSessionChunk` (type `text`).
   - **Event chunk:** persist as `EditSessionChunk` (type `event`). Events include `inference_start`, `inference_stop`, `tool_calling`, `tool_called`, `agent_error`.
   - **Progress chunk:** persist as `EditSessionChunk` (type `progress`). Short human-readable status messages (e.g. "Reading about.html", "Editing landing-1.html") derived from tool calls; shown in the chat bubble to make the agent feel "chatty" during long tasks.
   - **Message chunk:** persist as a new `ConversationMessage` (grows the LLM history).
   - **Done chunk:** persist as `EditSessionChunk` (type `done`).
8. After the generator is exhausted, set status to `Completed`.
9. Auto-commit and push changes to the workspace branch.
10. On any exception: set status to `Failed`, write a `Done` chunk with the error message.

### 4.3 EditSession Status Transitions

```
                              ┌─── (cancel before pickup) ──→ Cancelled
                              │
Pending ──→ Running ──→ Completed
               │
               ├──→ Failed            (exception during execution)
               │
               └──→ Cancelling ──→ Cancelled   (cooperative cancel)
```

| Status | Meaning |
|--------|---------|
| `Pending` | Created, waiting for Messenger worker to pick it up |
| `Running` | Handler is actively streaming from the LLM |
| `Cancelling` | Cancel requested by user; handler will stop at next iteration |
| `Completed` | Agent finished successfully |
| `Failed` | Agent threw an exception |
| `Cancelled` | Handler acknowledged the cancellation and stopped |

### 4.4 Dual Persistence: Chunks vs. Messages

A single turn produces data for **two independent persistence paths**. This is an important architectural distinction that is easy to overlook.

```
                                  Generator yields chunks
                                          │
                           ┌──────────────┼──────────────┐
                           │              │              │
                     chunkType=text  chunkType=event  chunkType=progress  chunkType=message
                           │              │              │                    │
                           ▼              ▼              ▼                    ▼
                    EditSessionChunk  EditSessionChunk  EditSessionChunk  ConversationMessage
                    (type: text)      (type: event)     (type: progress)   (role: user/assistant)
                           │              │              │                    │
                           └──────┬───────┴──────────────┘                    │
                                  │                                           │
                           edit_session_chunks                          conversation_messages
                           table                    table
                                  │                      │
                           Read by: frontend        Read by: RunEditSessionHandler
                           (poll + page resume)     (loadPreviousMessages on next turn)
                                  │                      │
                           Purpose: UI display      Purpose: LLM history replay
```

**What goes where:**

| Data | `EditSessionChunk` (events) | `ConversationMessage` |
|------|-----------------------------|-----------------------|
| User instruction | — | Yes (role: `user`) |
| Assistant text response | Yes (type: `text`, streamed) | Yes (role: `assistant`, complete) |
| Assistant note-to-self | Yes (type: `event`, if shown as tool call for `write_note_to_self`) | Yes (role: `assistant_note_to_self`) — see section 4.5 |
| Tool call request | Yes (type: `event`, kind: `tool_calling`, with truncated inputs) | **No** — filtered out |
| Tool call result | Yes (type: `event`, kind: `tool_called`, with truncated output) | **No** — filtered out |
| Progress message | Yes (type: `progress`, human-readable status e.g. "Reading X", "Editing Y") | **No** — UI only |
| Inference start/stop | Yes (type: `event`) | — |
| Done marker | Yes (type: `done`) | — |

**Why tool calls are excluded from `ConversationMessage`:** Within a single turn, the LLM API exchange may involve multiple round-trips (user message → assistant requests tool call → tool result → assistant requests another tool call → ... → assistant final text response). The NeuronAI `CallbackChatHistory` holds the full chain in memory during the turn. But only the bookend messages — the user instruction and the assistant's final text response — are persisted to `conversation_messages`. Tool call and tool call result messages are intentionally filtered out in `LlmContentEditorFacade` because replaying them in subsequent API calls causes 400 Bad Request errors (tool call IDs and schemas must match the current request's tool definitions exactly).

This means the LLM on subsequent turns sees a compressed history: user said X, assistant responded Y — without the intermediate tool mechanics. This works because the assistant's final text response is a natural-language summary of everything it did, including tool calls.

**Why tool calls _are_ kept in `EditSessionChunk`:** The frontend's technical container (the expandable chevron on each turn) shows what the agent did — which tools it called, with what inputs, and what results it got. This data comes from `event` chunks, not from `ConversationMessage`. That is why tool call details survive page reloads and appear in the UI even though they are absent from the LLM's conversation history.

The "Dump agent context" troubleshooting feature shows the `ConversationMessage` path — i.e., what the LLM will actually see on the next turn, including assistant note-to-self messages (labeled as `--- NOTE TO SELF ---`).

### 4.5 Assistant note-to-self (assistant_note_to_self)

**What it is:** Short, internal summaries the assistant can write whenever it wants to remember something long-term (e.g. what was done, what might be relevant next). They improve multi-turn intelligence without cluttering the visible chat.

**How it is produced:** The agent has a tool `write_note_to_self` (required string `note`). Output instructions encourage the agent to call it whenever it wants to scribble a note — during the turn, not only at the end (turns can be long). When the facade callback sees a `ToolCallMessage` with this tool, it collects the note; when the final `AssistantMessage` is received, it persists the assistant message first, then the collected notes, so the stored order is assistant → assistant_note_to_self(s). See [#83](https://github.com/dx-tooling/sitebuilder-webapp/issues/83).

**Where it lives:** In `conversation_messages` with role `assistant_note_to_self` and `content_json` like `{"content": "…"}`. The `MessageSerializer` maps them back to `AssistantMessage` with a `[Note to self from previous turn:]` prefix when building LLM context.

**Context shape:** The context sent to the LLM is **conversation-shaped**: system prompt → user → assistant → assistant_note_to_self (→ …) → user → assistant → assistant_note_to_self → …. Notes are not injected into the system prompt; they appear in the message stream so the agent sees a natural turn order. When trimming context (e.g. for token limits), only large tool-call/tool-result payloads are intended to be stripped; the user/assistant/assistant_note_to_self sequence is preserved.

**UI:** Assistant note-to-self is **not** shown as part of the assistant's chat bubble (the visible reply is the only assistant text there). It **may** be shown as a tool call in the technical details (same as other tools), so the agent’s use of the notepad can appear there if desired.

---

## 5. Streaming: Chunk Polling

The frontend does **not** use WebSockets or Server-Sent Events. Instead, it uses short-interval HTTP polling with non-overlapping requests.

### 5.1 Polling Flow

**Endpoint:** `GET /chat-based-content-editor/poll/{sessionId}?after={lastChunkId}`

1. Frontend sends `after=N` where `N` is the ID of the last chunk it received.
2. Backend queries `EditSessionChunk` where `session = :session AND id > :after`, ordered by `id ASC`, limit 100.
3. Returns `{ chunks, lastId, status, contextUsage }`.
4. Frontend processes each chunk:
   - `text` → append to the streaming markdown renderer
   - `event` → update the technical messages container (Working/Thinking badges)
   - `progress` → append a short status line in the chat bubble (e.g. "Reading about.html", "Editing …") so the agent feels "chatty" during long tasks
   - `done` → stop polling, reset UI
5. If `status` is `completed`, `failed`, or `cancelled`, stop polling even if no `done` chunk arrived yet (defensive).
6. Otherwise, schedule the next poll after 500ms (non-overlapping `setTimeout`, never `setInterval`).

**Why "Thinking" can last several seconds with no tool badges:** The LLM API streams tool-call arguments in many small chunks. The agent only emits an `event` chunk (e.g. `tool_calling`) when the **full** tool call has been received and parsed. Until then, the session has no new chunks to return, so the frontend keeps showing "Thinking". On the wire (see **LLM Logging Book**), you will see many `← chunk` lines with small `tool_calls[].function.arguments` fragments; that traffic is normal and indicates the model is actively streaming a tool call.

### 5.2 Page Resume

If the user refreshes or navigates away and returns:

1. `ChatBasedContentEditorController::show()` checks for EditSessions in `Pending`, `Running`, or `Cancelling` state.
2. If found, it serializes the session's existing chunks and passes them to the Twig template as `activeSession`.
3. The Stimulus controller's `connect()` calls `resumeActiveSession()`:
   - Replays all existing chunks to rebuild the UI state
   - Starts polling from `lastChunkId` to pick up where it left off

This makes the streaming UI fully resumable across page loads.

### 5.3 Context Usage Polling (AI Budget Bar)

A separate polling loop (2500ms interval) fetches token/cost data from `GET /chat-based-content-editor/{conversationId}/context-usage` to update the "AI budget" bar. This is independent of the chunk polling.

**Two metrics, two semantics:**

| Metric | Meaning | Behavior |
|--------|---------|----------|
| **Bar (X of Y used)** | **Current context** — bytes/tokens that would be in the next LLM request | Messages + system-prompt estimate + **only the active running session’s** event chunk bytes (tool calls/results). When no turn is running, or when the frontend does not send a session, event chunks are excluded — so the bar **can shrink** when a turn ends. |
| **Est. $Z** | **Cumulative cost** — total input + output cost so far | All conversation messages + all event chunks (input) and all text chunks (output). **Only grows**; does not depend on which session is active. |

**How the bar reflects the current turn:** While a turn is in progress, the frontend includes `?sessionId={id}` on the context-usage request. The backend then adds that session’s event chunk bytes (when its status is `Running`) to the current-context sum, so the bar grows during tool use and shrinks once the turn completes and polling continues without a session. Cost is always computed from the full conversation and does not change when the bar shrinks.

---

## 6. Cancellation

Cancellation looks simple on the surface — set a flag, check it, stop — but it has **four layers of non-obvious complexity**: cooperative signalling, entity hydration constraints, conversation history corruption, and frontend visual state.

### 6.1 User-Initiated Cancel Flow

1. User clicks the **Stop** button in the UI.
2. Frontend sends `POST /chat-based-content-editor/cancel/{sessionId}` with CSRF token.
3. Controller validates ownership and checks the current status:
   - If already terminal (`Completed`, `Failed`, `Cancelled`): returns `{ success: true, alreadyFinished: true }`.
   - Otherwise: sets status to `Cancelling` and flushes.
4. Frontend keeps polling — it does **not** stop immediately. This ensures all chunks produced before cancellation are displayed.
5. The handler detects `Cancelling` at the next DBAL status check (see 6.2), performs history cleanup (see 6.3), writes a `Done` chunk with message "Cancelled by user.", sets status to `Cancelled`, and returns.
6. Frontend's next poll receives the `done` chunk and/or `cancelled` status, stops polling, transitions the technical container to cancelled visual state (see 6.4), and resets the UI.

### 6.2 Why Cooperative Cancellation? (And Why Not `refresh()`?)

PHP does not support thread interruption. The Messenger handler runs synchronously in a worker process, iterating a generator that makes HTTP calls to the LLM API. The only safe way to stop it is to have the handler check a flag on each iteration — a database column that can be set by a separate HTTP request (the cancel endpoint).

The status check uses a direct DBAL query (`SELECT status FROM edit_sessions WHERE id = ?`) rather than `entityManager->refresh($session)`. This is because the `EditSession` entity uses PHP `readonly` properties (e.g. `$createdAt`, `$conversation`, `$instruction`), and Doctrine's `refresh()` tries to re-hydrate all mapped properties — which PHP 8.2+ blocks on already-initialized readonly properties, throwing `"Attempting to change readonly property"`. The DBAL query sidesteps this entirely by reading only the status value without touching the ORM entity.

One `SELECT` per generator iteration is negligible compared to the LLM network latency per chunk.

### 6.3 Conversation History Repair (Synthetic Assistant Message)

This is the subtlest cancellation pitfall. By the time a cancel is detected in the generator loop, the conversation history is already partially written:

1. `streamEditWithHistory()` creates a `UserMessage` and adds it to the chat history.
2. The `CallbackChatHistory` callback fires, yielding a `message` chunk.
3. The handler persists it as a `ConversationMessage` in the database.
4. The agent starts LLM inference and tool execution...
5. **Cancel is detected** — the handler returns.

At this point the database has a **user message with no matching assistant message**. On the next turn, `loadPreviousMessages()` feeds the LLM a history with two consecutive `USER` messages:

```
USER:  "List all files you can see."    ← cancelled turn, no response
USER:  "What is your name?"             ← new turn
```

The LLM interprets the first unanswered user message as the actual request and responds to it — answering the stale, cancelled question instead of the new one.

**Fix:** When cancellation is detected in the loop, the handler persists a synthetic assistant `ConversationMessage` with content `"[Cancelled by the user — disregard this turn.]"` before setting the status to `Cancelled`. This closes the user-assistant pair so the next turn's history is well-formed:

```
USER:       "List all files you can see."
ASSISTANT:  "[Cancelled by the user — disregard this turn.]"
USER:       "What is your name?"
```

Note: this is only necessary for **in-loop** cancellation. Pre-start cancellation (before `streamEditWithHistory()` is called) never persists any messages, so no cleanup is needed.

### 6.4 Frontend Visual Treatment of Cancelled Turns

Cancelled turns must be visually distinguishable from completed or failed turns. Without this, a cancelled turn with no response looks identical to a completed turn that produced no output.

**Three visual signals mark a cancelled turn:**

| Element | Normal (completed) | Cancelled |
|---------|-------------------|-----------|
| User instruction bubble | Full opacity | 60% opacity (`opacity-60`) |
| Assistant response area | No left border, shows response text | Amber left border (`border-l-2 border-amber-500`), italic "Cancelled" text |
| Technical container (toolbar) | Green "All set" with checkmark | Amber "Cancelled" with warning icon |

The technical container styling uses `getCancelledContainerStyle()` (amber/orange palette) instead of `getCompletedContainerStyle()` (green palette). This applies in two code paths:

1. **Page reload** — `renderCompletedTurnsTechnicalContainers()` checks `turn.status === "cancelled"` and uses the amber style.
2. **Live cancellation** — `handleChunk()` detects the cancellation done-chunk and passes `cancelled: true` to `markTechnicalContainerComplete()`.

**CSS pitfall:** The technical container header has a shimmer animation (`@keyframes shimmer`) that gives a "working" effect. This animation is explicitly stopped via CSS selectors for completed (`from-green-50/80`) and failed (`from-red-50/80`) states. The amber cancelled state (`from-amber-50/80`) must also be included in this CSS stop-list, otherwise a cancelled container shows an active shimmer over a static amber background — misleadingly suggesting work is still happening.

---

## 7. Stale Session Recovery

Two failure modes can leave the system in a stuck state:

### 7.1 Abandoned Conversations

If the user closes their browser during an active conversation:
- The heartbeat stops (frontend `conversation_heartbeat_controller.ts` sends a POST every 10 seconds to update `Conversation.lastActivityAt`).
- The Conversation stays `ONGOING` and the workspace stays `IN_CONVERSATION`.

**Recovery:** `ChatBasedContentEditorFacade::releaseStaleConversations()` finds conversations where `lastActivityAt` is older than 5 minutes, marks them `FINISHED`, and transitions their workspaces to `AVAILABLE_FOR_CONVERSATION`.

### 7.2 Stuck EditSessions

If the Messenger worker crashes mid-execution (OOM, deploy, etc.):
- The EditSession stays in `Running` or `Cancelling` forever.
- No `Done` chunk is written, so the frontend would poll indefinitely.

**Recovery:** `ChatBasedContentEditorFacade::recoverStuckEditSessions()`:
- `Running` sessions older than 30 minutes → set to `Failed` with a `Done` chunk.
- `Cancelling` sessions older than 2 minutes → set to `Cancelled` with a `Done` chunk.

### 7.3 Scheduled Cleanup

The console command `app:release-stale-conversations` runs both recovery methods. It is intended for cron execution:

```bash
# Run every 2 minutes
*/2 * * * * cd /var/www && php bin/console app:release-stale-conversations --timeout=5 --running-timeout=30 --cancelling-timeout=2
```

The stale conversation cleanup also runs opportunistically in `ProjectController::list()` when any user views the project list.

---

## 8. Heartbeat Mechanism

The heartbeat is a lightweight presence tracker that enables stale-conversation detection.

- **Frontend:** `conversation_heartbeat_controller.ts` — Stimulus controller attached to the editor page. Sends `POST /conversation/{id}/heartbeat` every 10 seconds.
- **Backend:** `ChatBasedContentEditorController::heartbeat()` — validates ownership, checks the conversation is `ONGOING`, updates `Conversation.lastActivityAt`.
- **Cleanup dependency:** Without heartbeats, `releaseStaleConversations()` cannot distinguish between an active user and an abandoned session.

The heartbeat controller stops itself if it receives a 403, 404, or 400 response (conversation no longer accessible).

---

## 9. Cross-Vertical Boundaries

The conversation system spans multiple verticals. Here is how they interact through facades:

```
┌─────────────────────────────────────────────────────────────────────┐
│ ChatBasedContentEditor (owns conversations and edit sessions)       │
│   Domain:   Conversation, EditSession, EditSessionChunk,            │
│             ConversationMessage, ConversationService                │
│   Facade:   ChatBasedContentEditorFacadeInterface                   │
│             (releaseStaleConversations, recoverStuckEditSessions)   │
│   Infra:    RunEditSessionHandler, ReleaseStaleConversationsCommand │
│   Present:  ChatBasedContentEditorController, Stimulus controllers  │
├─────────────────────────────────────────────────────────────────────┤
│ Uses facades from:                                                  │
│   • WorkspaceMgmt    → workspace transitions, commit & push, PR     │
│   • ProjectMgmt      → project info, LLM API key, agent config      │
│   • Account          → user identity (email for commit author)      │
│   • LlmContentEditor → agent execution (streamEditWithHistory)      │
└─────────────────────────────────────────────────────────────────────┘
```

The `LlmContentEditor` vertical owns the agent itself (`ContentEditorAgent`) and exposes `LlmContentEditorFacadeInterface::streamEditWithHistory()` — a PHP Generator that yields `EditStreamChunkDto` objects. The `ChatBasedContentEditor` vertical consumes this generator in `RunEditSessionHandler` and persists the results as `EditSessionChunk` entities.

---

## 10. Frontend Architecture

All frontend code lives within the `ChatBasedContentEditor` vertical:

```
src/ChatBasedContentEditor/Presentation/Resources/
├── assets/controllers/
│   ├── chat_based_content_editor_controller.ts   ← main editor controller
│   ├── chat_editor_helpers.ts                     ← shared types and utilities
│   ├── markdown_renderer.ts                       ← streaming markdown rendering
│   ├── conversation_heartbeat_controller.ts       ← presence tracking
│   ├── dist_files_controller.ts                   ← preview file polling
│   ├── html_editor_controller.ts                  ← manual HTML editing
│   ├── prompt_suggestions_controller.ts           ← suggestion chips
│   ├── markdown_controller.ts                     ← static markdown rendering
│   └── workspace_setup_controller.ts              ← setup waiting page
└── templates/
    ├── chat_based_content_editor.twig             ← main editor template
    ├── workspace_setup.twig                       ← setup waiting page
    └── workspace_problem.twig                     ← error state page
```

### Key Stimulus Values Passed from Twig

| Value | Purpose |
|-------|---------|
| `runUrl` | `POST` endpoint to create a new EditSession |
| `pollUrlTemplate` | `GET` endpoint template for chunk polling (`__SESSION_ID__` placeholder) |
| `cancelUrlTemplate` | `POST` endpoint template for cancelling an EditSession |
| `conversationId` | Current conversation UUID |
| `contextUsageUrl` | `GET` endpoint for token/cost data |
| `activeSession` | Serialized active EditSession for page-resume (null if none) |
| `turns` | Array of completed turns for initial rendering |
| `readOnly` | Boolean — disables input form and action buttons |
| `translations` | Translated strings for all dynamic UI text |

### UI State Machine (Submit/Cancel Button)

```
Idle                          Working                       Cancelling
┌───────────────┐  submit    ┌──────────────┐  click Stop ┌──────────────┐
│ [Make changes]│───────────→│ [Making...]  │────────────→│ [Stopping...]│
│               │            │ [Stop]       │             │ (disabled)   │
└───────────────┘            └──────────────┘             └──────┬───────┘
       ▲                         │                               │
       │                         │ done/completed/failed         │ done/cancelled
       └─────────────────────────┴───────────────────────────────┘
```

---

## 11. Database Schema (Key Tables)

| Table | PK | Key Columns |
|-------|-----|-------------|
| `conversations` | UUID | `workspace_id`, `user_id`, `status` (VARCHAR 32), `workspace_path`, `last_activity_at`, `created_at` |
| `edit_sessions` | UUID | `conversation_id` (FK), `instruction` (TEXT), `status` (VARCHAR 32), `created_at` |
| `edit_session_chunks` | AUTO_INCREMENT int | `session_id` (FK), `chunk_type` (VARCHAR 32), `payload_json` (TEXT), `created_at` |
| `conversation_messages` | AUTO_INCREMENT int | `conversation_id` (FK), `role` (VARCHAR 32), `content_json` (TEXT), `sequence` (int), `created_at` |

The `edit_session_chunks` table uses an auto-incrementing integer PK (not UUID) because chunk polling relies on `id > :after` ordering. The index `idx_session_chunk_polling` on `(session_id, id)` makes this query efficient.

---

## 12. Sequence Diagram: Full Prompt Execution

```
User        Browser/Stimulus          Controller            Messenger Worker         Database
 │               │                        │                        │                    │
 │  type prompt  │                        │                        │                    │
 │──────────────>│                        │                        │                    │
 │               │  POST /run             │                        │                    │
 │               │───────────────────────>│                        │                    │
 │               │                        │  INSERT EditSession    │                    │
 │               │                        │  (status=Pending)      │                    │
 │               │                        │────────────────────────────────────────────>│
 │               │                        │  dispatch message      │                    │
 │               │                        │───────────────────────>│                    │
 │               │  { sessionId }         │                        │                    │
 │               │<───────────────────────│                        │                    │
 │               │                        │                        │                    │
 │               │  start polling ────────────────────────────────────────────────────  │
 │               │  GET /poll?after=0     │                        │  status=Running    │
 │               │───────────────────────>│                        │───────────────────>│
 │               │                        │                        │                    │
 │               │                        │                        │  stream from LLM   │
 │               │                        │                        │  ┌──────────────┐  │
 │               │                        │                        │  │ foreach chunk│  │
 │               │                        │                        │  │ DBAL status  │  │
 │               │                        │                        │  │ persist chunk│─>│
 │               │                        │                        │  │ flush()      │  │
 │               │  GET /poll?after=N     │                        │  └──────────────┘  │
 │               │───────────────────────>│                        │                    │
 │               │  { chunks, status }    │  read chunks > N       │                    │
 │               │<───────────────────────│<────────────────────────────────────────────│
 │  render text  │                        │                        │                    │
 │<──────────────│                        │                        │                    │
 │               │                        │                        │  status=Completed  │
 │               │                        │                        │───────────────────>│
 │               │                        │                        │  commit & push     │
 │               │  GET /poll → done      │                        │                    │
 │               │───────────────────────>│                        │                    │
 │               │  { status: completed } │                        │                    │
 │               │<───────────────────────│                        │                    │
 │               │  stop polling          │                        │                    │
 │               │  reset UI              │                        │                    │
 │<──────────────│                        │                        │                    │
```

---

## 13. Key File Reference

| Layer | File | Purpose |
|-------|------|---------|
| **Entity** | `src/ChatBasedContentEditor/Domain/Entity/Conversation.php` | Conversation aggregate root |
| **Entity** | `src/ChatBasedContentEditor/Domain/Entity/EditSession.php` | Single prompt execution |
| **Entity** | `src/ChatBasedContentEditor/Domain/Entity/EditSessionChunk.php` | Streaming chunk (text/event/done) |
| **Entity** | `src/ChatBasedContentEditor/Domain/Entity/ConversationMessage.php` | Persisted LLM history message |
| **Enum** | `src/ChatBasedContentEditor/Domain/Enum/EditSessionStatus.php` | Pending, Running, Cancelling, Completed, Failed, Cancelled |
| **Enum** | `src/ChatBasedContentEditor/Domain/Enum/ConversationStatus.php` | ONGOING, FINISHED |
| **Enum** | `src/ChatBasedContentEditor/Domain/Enum/EditSessionChunkType.php` | Text, Event, Done |
| **Enum** | `src/ChatBasedContentEditor/Domain/Enum/ConversationMessageRole.php` | user, assistant, assistant_note_to_self, tool_call, tool_call_result |
| **Service** | `src/ChatBasedContentEditor/Domain/Service/ConversationService.php` | Start, finish, review, find |
| **Facade** | `src/ChatBasedContentEditor/Facade/ChatBasedContentEditorFacade.php` | Stale cleanup, stuck session recovery |
| **Handler** | `src/ChatBasedContentEditor/Infrastructure/Handler/RunEditSessionHandler.php` | Async agent execution + cancellation |
| **Message** | `src/ChatBasedContentEditor/Infrastructure/Message/RunEditSessionMessage.php` | Messenger dispatch DTO |
| **Command** | `src/ChatBasedContentEditor/Infrastructure/Command/ReleaseStaleConversationsCommand.php` | Cron-based cleanup |
| **Controller** | `src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php` | All HTTP endpoints |
| **Frontend** | `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts` | Main Stimulus controller |
| **Frontend** | `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_editor_helpers.ts` | Shared types + utilities |
| **Frontend** | `src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/conversation_heartbeat_controller.ts` | Presence heartbeat |
| **Template** | `src/ChatBasedContentEditor/Presentation/Resources/templates/chat_based_content_editor.twig` | Editor page |
| **Agent** | `src/LlmContentEditor/Facade/LlmContentEditorFacade.php` | Agent orchestration + streaming generator |
| **Agent** | `src/LlmContentEditor/Domain/Agent/ContentEditorAgent.php` | The coding agent itself |
