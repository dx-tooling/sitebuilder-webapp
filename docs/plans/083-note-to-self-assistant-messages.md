# Implementation Plan: Agent note-to-self messages (#83)

> **Issue:** [#83 — Enhancement: Agent note-to-self messages for improved multi-turn intelligence](https://github.com/dx-tooling/sitebuilder-webapp/issues/83)

## Summary

Introduce **note-to-self** assistant messages: short, internal summaries produced by the agent at the end of each turn. They are persisted and always included in the context sent to the LLM API, but **never shown in the chat UI**. The "Dump agent context" troubleshooting feature must include them so it continues to reflect the full, actual agent context.

---

## Background

Today:

- Only **user** and **assistant** (visible) messages are persisted to `conversation_messages`. Tool-call and tool-call-result messages are filtered out (replaying them causes API errors).
- The LLM on the next turn sees: user said X, assistant responded Y — with no structured record of what tools were used or what was done.
- The assistant's **final visible text** is the only "memory"; there is no dedicated, guaranteed place for internal state.
- Context-window trimming in `CallbackChatHistory` can drop older messages; we already preserve the latest `UserMessage` when trimming would otherwise clear the history (#75).

Note-to-self messages close this gap: they are a dedicated channel for the agent to record what it did and what might be relevant next, and they must **always** be present in the prompt (e.g. exempt from trimming or in a dedicated always-included block).

---

## Design

### 1. New role: `assistant_note`

- Add **`ConversationMessageRole::AssistantNote = 'assistant_note'`** in `src/ChatBasedContentEditor/Domain/Enum/ConversationMessageRole.php`.
- Persist note-to-self messages with this role in `conversation_messages` (same table, new enum value).
- **Database:** Add a migration so the `role` column accepts the new value (if the column is an ENUM, extend it; if it's a string/varchar, no schema change may be needed — verify with existing enum storage).

### 2. Persistence (LlmContentEditorFacade + RunEditSessionHandler)

- **Producing note-to-self:** The agent will produce a note as part of its final response. This can be done in two ways:
  - **Option A (recommended):** Instruct the agent (via output instructions) to end its reply with a block that we can parse, e.g. `[NOTE TO SELF: ...]`. After the stream completes, we strip that block from the visible assistant message and persist it as a separate `ConversationMessage` with role `AssistantNote`. The visible message stored is the same as today (without the note block).
  - **Option B:** Add a dedicated tool or structured output for "submit_note_to_self" that the agent calls before ending. The handler persists that as a separate message; no parsing of the assistant text is needed.
- **Persistence filter:** In `LlmContentEditorFacade::streamEditWithHistory`, the callback that decides what to persist currently allows `UserMessage` and `AssistantMessage` (with non-empty content). We do **not** persist note-to-self from the stream as a separate message type from NeuronAI — we either parse it from the final assistant content (Option A) or handle a tool/structured output (Option B). So the facade will:
  - When we have a "note" (parsed or from tool): enqueue **two** DTOs for persistence — one `assistant` (visible text only) and one `assistant_note` (note content only). The handler must persist both with the correct roles.
- **RunEditSessionHandler::persistConversationMessage:** Already uses `ConversationMessageRole::from($dto->role)`. Once the enum has `AssistantNote`, it will persist note-to-self messages. No change needed except that the facade must emit a `ConversationMessageDto` with `role === 'assistant_note'` and appropriate `contentJson`.

### 3. Loading history and sending to the LLM

- **RunEditSessionHandler::loadPreviousMessages:** Currently loads all messages in order and passes them as `ConversationMessageDto[]`. Note-to-self messages will be in the table with role `assistant_note`, so they are already loaded and passed to the facade.
- **MessageSerializer:** Must support `assistant_note` in both directions:
  - **fromDto:** When `role === 'assistant_note'`, deserialize to an `AssistantMessage` with content that clearly marks it as internal, e.g. `"[Note to self from previous turn:] " . $content` so the model sees it as its own prior note.
  - **toDto:** We only persist note-to-self from our parsing/tool path, not from a NeuronAI message type; so we may not need toDto for assistant_note unless we ever round-trip. For consistency, we can support it: if we introduce an internal NeuronAI message type or a wrapper that carries a "isNoteToSelf" flag, toDto would emit role `assistant_note`. For Option A, we build the DTO manually when we parse the note block.
- **CallbackChatHistory / trimming:** Note-to-self messages are replayed as normal `AssistantMessage` instances in the history. The parent's trimming removes from the front. To **guarantee** note-to-self messages remain in context, we have two options:
  - **Option 1:** After parent trim, re-append the last N note-to-self messages (or all note-to-self from the conversation) if they were trimmed. This requires either tracking which messages were "note" in the history, or re-building a small "recent notes" block from the DTOs and prepending/appending it. The cleanest approach: when building `initialMessages` for `CallbackChatHistory`, we could pass note-to-self messages as regular AssistantMessages; then in `trimHistory()` we do not specially preserve them beyond what the parent does — but we could **exempt** the last K note-to-self messages from trimming (e.g. never remove them from the end). That requires CallbackChatHistory to know which messages are "note" type; that information is lost once we convert DTO → NeuronAI Message. So we need either to mark AssistantMessage in a way we can detect (e.g. content always starts with a sentinel), or to keep a separate list of "recent notes" that we always append to the prompt. Simpler approach: **always append a "Recent notes to self" block to the system prompt or to the conversation** when we have note-to-self messages. That way they are not subject to trimming. Implementation: when building the initial messages in the facade, we could append a single synthetic "system" or "user" message that contains the concatenated recent note-to-self content (e.g. "RECENT NOTES TO SELF:\n- ...\n- ..."). Or we keep note-to-self as regular AssistantMessage in the history and ensure in CallbackChatHistory that we never trim the last N messages that match a pattern (e.g. content starts with "[Note to self"). That's fragile. **Recommended:** When loading previous messages, after building `initialMessages`, append a synthetic message (or inject into system prompt) the text of the last M note-to-self messages (e.g. M=10 or all of them if small). So the "guaranteed in context" requirement is met by injecting them into the system prompt or as a single dedicated user/assistant message that we never trim. System prompt injection is clean: e.g. "RECENT NOTES TO SELF (for context only):\n" . implode("\n", $noteContents). Then the conversation history can still be trimmed normally; the notes are always in the system prompt.
- **Concrete recommendation:** Store note-to-self in `conversation_messages` with role `assistant_note`. When building context for the LLM: (1) Load all messages. (2) Build `initialMessages` from user and assistant (visible) messages only for the normal conversation flow; (3) Collect all (or last N) note-to-self message contents; (4) Append to the **system prompt** a section "RECENT NOTES TO SELF:\n" . implode("\n", $notes). That way notes are always present and never trimmed. The conversation history in the API remains user/assistant only (no need to send note-to-self as separate assistant messages if we've already folded them into the system prompt). So we have two paths: (A) Include note-to-self as AssistantMessage in the history (so they appear in the same order as they were created) and protect them from trimming in CallbackChatHistory, or (B) Don't put them in the history; instead inject their content into the system prompt. Option B is simpler and guarantees they're always present. We'll go with **Option B:** note-to-self messages are loaded with the rest; when building the prompt we add a "RECENT NOTES TO SELF" section to the system prompt; the `initialMessages` passed to the agent contain only user and assistant (visible) messages, so the conversation view for the model is clean and notes are in the system prompt.

### 4. Dump agent context

- **LlmContentEditorFacade::buildAgentContextDump:** Currently iterates over `$previousMessages` and prints each with `--- ROLE ---` and content. The dump is built from the same `previousMessages` that the controller collects from `$conversation->getMessages()`. So once we persist note-to-self messages, they will be in `getMessages()` and thus in `$previousMessages`. We must:
  - Ensure the dump formatter **includes** messages with role `assistant_note` and labels them clearly, e.g. `--- NOTE TO SELF ---` so the troubleshooting view shows the full context.
- **ChatBasedContentEditorController::dumpAgentContext:** Already passes all conversation messages to the facade. No change needed except that the facade's dump formatter must handle the new role (and optionally pass working folder path for consistency with RunEditSessionHandler; see plan 079).

### 5. Chat UI: never show note-to-self

- The chat UI builds **turns** from **EditSession** (instruction + chunks), not from `ConversationMessage`. So the list of messages in the thread is not rendered from `conversation_messages`. We must still ensure no future API or export exposes note-to-self. Any place that returns "conversation messages" for display (e.g. a REST endpoint that lists messages) must filter out role `assistant_note`. Currently the main show action does not return a list of conversation messages to the template; it returns turns derived from edit sessions. So the only consumer of "all messages" today is the handler (for LLM) and the dump (for troubleshooting). Both should see note-to-self. Add an explicit filter in any code path that builds "messages for UI display" so that when we add such a path later, the rule is clear: exclude `ConversationMessageRole::AssistantNote`. Document this in the plan and in conversationbook.md.

### 6. Prompting

- Update **agent output instructions** (project-level template and/or default) to ask the agent to end each turn with a brief note in a fixed format, e.g. `[NOTE TO SELF: summary of what was done and what might be relevant next.]`. The parsing logic in the facade (or in a small dedicated service) will strip this block and persist it as a separate message.

---

## Files to touch

| File | Change |
|------|--------|
| `src/ChatBasedContentEditor/Domain/Enum/ConversationMessageRole.php` | Add `AssistantNote = 'assistant_note'`. |
| `migrations/` | New migration if DB enum/constraint must be updated for `role`. |
| `src/LlmContentEditor/Facade/Dto/ConversationMessageDto.php` | Extend role type to include `'assistant_note'` in docblock/contract. |
| `src/LlmContentEditor/Infrastructure/ChatHistory/MessageSerializer.php` | Support `assistant_note` in `fromDto` (→ AssistantMessage with prefixed content or distinct handling); `toDto` if we ever serialize from a NeuronAI message. |
| `src/LlmContentEditor/Facade/LlmContentEditorFacade.php` | (1) After stream completes, parse final assistant content for `[NOTE TO SELF: ...]`; strip it and persist two messages: one assistant (visible), one assistant_note. (2) When building initial messages for the agent, collect note-to-self from previousMessages and inject their content into the system prompt; pass only user/assistant (non-note) messages as conversation history. (3) In persistence callback, allow saving assistant_note DTOs (we will enqueue them from the parsing step, not from the callback — so the callback may only see the visible assistant message; the note is persisted separately). (4) buildAgentContextDump: include messages with role `assistant_note` and label them `--- NOTE TO SELF ---`. |
| `src/LlmContentEditor/Domain/Agent/ContentEditorAgent.php` or agent config | Ensure system prompt can receive the "RECENT NOTES TO SELF" section (facade builds full system prompt and passes to agent; so the facade must be able to append to the system prompt when building the request — verify NeuronAI agent API). |
| `src/ChatBasedContentEditor/Infrastructure/Handler/RunEditSessionHandler.php` | loadPreviousMessages already returns all messages; persistConversationMessage already uses enum from DTO — no change if role is valid. Ensure when we persist a message with role `assistant_note` the enum is used (ConversationMessageRole::AssistantNote). |
| `src/ProjectMgmt/` (agent template) | Add to default output instructions: ask the agent to end with `[NOTE TO SELF: ...]` (exact format TBD). |
| `docs/conversationbook.md` | Document note-to-self: new row in "What goes where", explanation that they are in ConversationMessage, included in dump and in system prompt, never in UI. |

---

## Tests (critical for quality)

### Unit tests

1. **MessageSerializer**
   - `fromDto` with role `assistant_note` and valid contentJson returns an `AssistantMessage` whose content is the note (optionally prefixed with "[Note to self from previous turn:]").
   - `toDto` for an assistant message that we mark as note (if we add a way to mark it) produces role `assistant_note` and correct contentJson. If we don't have a NeuronAI type for note, skip toDto test or test a helper that builds the DTO.

2. **LlmContentEditorFacade (or a dedicated parser service)**
   - Parsing: Given assistant content that ends with `[NOTE TO SELF: I added the footer.]`, extract note "I added the footer." and visible part without the block.
   - Parsing: Given assistant content without the block, no note is extracted.
   - Parsing: Malformed or multiple blocks — define behaviour (e.g. take last occurrence) and test it.

3. **LlmContentEditorFacade — buildAgentContextDump**
   - When `previousMessages` contains a message with role `assistant_note`, the dump string includes a section labeled e.g. `--- NOTE TO SELF ---` and the note content.
   - Order of messages in dump preserves sequence (user, assistant, note, user, ...).

4. **CallbackChatHistory** (if we change trimming behaviour for notes)
   - If we instead keep notes in history and protect them from trim: add a test that after trimming, the last N note-to-self messages (identified by content prefix) are still present. Otherwise skip.

### Integration tests

5. **Full flow**
   - Start a conversation; run an edit that produces a note (mock or real agent that returns content with `[NOTE TO SELF: ...]`); assert that two ConversationMessage rows are created (one assistant, one assistant_note) and that on the next turn loadPreviousMessages returns both, and that buildAgentContextDump includes the note.

6. **RunEditSessionHandler**
   - When the facade yields a Message chunk with role `assistant_note`, the handler persists it with ConversationMessageRole::AssistantNote (test via repository or in-memory DB).

### Architecture / boundaries

7. Ensure no vertical exposes note-to-self to the UI: any facade method that returns "messages for display" must filter out AssistantNote (add a test or arch rule if applicable).

---

## Implementation order (suggested)

1. Add enum value and migration; run quality.
2. MessageSerializer: support assistant_note in fromDto (and toDto if applicable); unit tests.
3. Parser for `[NOTE TO SELF: ...]` and facade logic to persist two messages (assistant + assistant_note); unit tests.
4. Facade: when building context for streamEditWithHistory, collect note-to-self from previousMessages and inject "RECENT NOTES TO SELF" into system prompt; pass only user/assistant (non-note) as conversation history. Unit test with mock previousMessages.
5. buildAgentContextDump: include and label assistant_note messages; unit test.
6. Agent template: add output instruction for note-to-self format.
7. Integration test: full turn with note parsing and persistence, next turn receives notes in context.
8. Docs: conversationbook.md update.
9. Run full quality and test suite; manual smoke test.

---

## Out of scope (per issue)

- Changing how tool-call/tool-result messages are replayed.
- Exposing note-to-self in the UI as a debug view (optional follow-up).

---

## References

- Issue [#83](https://github.com/dx-tooling/sitebuilder-webapp/issues/83)
- `docs/conversationbook.md` — "What goes where", "Dump agent context"
- `docs/plans/075-fix-infinite-get-workspace-rules-loop.md` — trim behaviour
- `docs/plans/079-workspace-path-in-system-prompt.md` — system prompt injection
