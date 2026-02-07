# LLM Logging Book

How the LLM low-level logging system works: wire-level HTTP capture, human-readable conversation logs, the CLI viewer, the HTML viewer, and the architecture tying it all together.

---

## 1. Overview

When the agent executes a turn, a complex exchange happens between the application and the LLM provider's API: HTTP requests carrying system prompts, conversation history, and tool definitions go out; streaming SSE responses carrying text fragments, tool call instructions, and metadata come back. This exchange is invisible by default.

The LLM logging system makes it visible through two complementary channels:

| Channel | Monolog Channel | Log File | Content | Audience |
|---------|-----------------|----------|---------|----------|
| **Wire log** | `llm_wire` | `var/log/llm-wire.log` | Raw HTTP traffic: full request/response bodies, headers, streaming chunks | Deep debugging, protocol-level analysis |
| **Conversation log** | `llm_conversation` | `var/log/llm-conversation.log` | Semantic events: user instructions, assistant responses, tool calls, errors | Quick understanding of what the agent did |

Both channels are **ephemeral developer tools**. They write to log files, not the database. They are enabled or disabled together via a single environment variable and are designed for real-time streaming via the CLI.

---

## 2. Enabling and Disabling

A single environment variable controls both logging channels:

```
LLM_WIRE_LOG_ENABLED=1   # Enabled
LLM_WIRE_LOG_ENABLED=0   # Disabled (default)
```

The default configuration:
- `.env` sets `LLM_WIRE_LOG_ENABLED=0` (off globally)
- `.env.dev` sets `LLM_WIRE_LOG_ENABLED=1` (on in dev)

To enable in other environments, set the variable in `.env.local` or the appropriate environment file and restart the messenger worker:

```bash
docker compose exec messenger php bin/console cache:clear
docker compose restart messenger
```

When disabled, no log files are written and there is zero runtime overhead -- the Guzzle middleware is not attached, the conversation observer is not registered, and no logger calls are made.

---

## 3. Architecture

```
                    ContentEditorAgent
                           │
                    provider() builds
                    OpenAI client
                           │
                ┌──────────┴──────────┐
                │                     │
         Guzzle HandlerStack    Agent SplObserver
         (when enabled)          (when enabled)
                │                     │
     ┌──────────┴──────────┐    ┌─────┴──────┐
     │                     │    │            │
  LlmWireLog         LoggingStream   LlmConversation
  Middleware          (SSE chunks)   LogObserver
     │                     │         │
     ▼                     ▼         ▼
  llm_wire logger       llm_wire   llm_conversation
  (→ request)           logger     logger
  (← response)         (← chunk)  (USER →, ASSISTANT →,
                                    TOOL_CALL, TOOL_RESULT,
                                    ERROR)
     │                     │              │
     └─────────┬───────────┘              │
               ▼                          ▼
    LlmWireLogProcessor         LlmWireLogProcessor
    (enriches with                (enriches with
     conversationId,               conversationId)
     workspaceId)
               │                          │
               ▼                          ▼
     var/log/llm-wire.log      var/log/llm-conversation.log
```

### 3.1 How the Wire Logger Hooks In

The `ContentEditorAgent` conditionally injects a Guzzle `HandlerStack` into the OpenAI provider. When `$wireLogger` is non-null, `LlmWireLogMiddleware::createHandlerStack()` builds a stack with a middleware that intercepts every HTTP request and response.

For **non-streaming responses** (rare in practice), the middleware logs the response status and headers. For **streaming responses** (the normal path), it wraps the response body in a `LoggingStream` decorator that buffers incoming bytes and emits each complete SSE line as a separate log entry.

This means the wire log captures the exact same data a proxy like mitmproxy would see, but without requiring any external tool.

### 3.2 How the Conversation Logger Hooks In

The `LlmContentEditorFacade` registers an `LlmConversationLogObserver` on the agent when wire logging is enabled. This observer implements PHP's `SplObserver` and listens for NeuronAI events:

- `ToolCalling` -- logged as `TOOL_CALL <name> (<inputs>)`
- `ToolCalled` -- logged as `TOOL_RESULT <name> (<N> chars)`
- `AgentError` -- logged as `ERROR <message>`

The facade itself logs the bookend messages:
- Before streaming: `USER → <prompt>`
- After streaming: `ASSISTANT → <response>` (truncated to 300 chars)
- On exception: `ERROR → <message>`

### 3.3 The Shared Processor

Both channels share a single Monolog processor, `LlmWireLogProcessor`, which reads the current `conversationId` and `workspaceId` from the `AgentExecutionContextInterface` and injects them into every log record's `extra` array. This is what makes per-conversation filtering possible -- both in the CLI viewer and the HTML viewer.

---

## 4. What Gets Logged

### 4.1 Wire Log (`llm-wire.log`)

Each agent turn produces entries in this order:

```
[datetime] llm_wire.DEBUG: → request {"method":"POST","url":"https://api.openai.com/v1/chat/completions","headers":{...},"body":{...}} {"conversationId":"019c...","workspaceId":"..."}
[datetime] llm_wire.DEBUG: ← response {"status":200,"headers":{...}} {"conversationId":"019c..."}
[datetime] llm_wire.DEBUG: ← chunk {"line":"data: {\"id\":\"chatcmpl-...\",\"choices\":[{\"delta\":{\"content\":\"I\"}}]}"} {"conversationId":"019c..."}
[datetime] llm_wire.DEBUG: ← chunk {"line":"data: {\"id\":\"chatcmpl-...\",\"choices\":[{\"delta\":{\"content\":\"'ll\"}}]}"} {"conversationId":"019c..."}
...
[datetime] llm_wire.DEBUG: ← chunk {"line":"data: [DONE]"} {"conversationId":"019c..."}
```

A single user prompt that triggers tool calls produces **multiple** request-response-stream sequences (one per LLM API round-trip).

The full request body includes:
- The system prompt
- Complete conversation history
- All tool definitions (JSON schema)
- The current user message

Nothing is redacted. This is intentional -- the wire log exists precisely because you need to see exactly what the LLM receives and returns.

### 4.2 Conversation Log (`llm-conversation.log`)

The same turn produces a much more concise output:

```
[2025-02-07 14:30:00] [019c3759-4911-7974-87e2-b2535896279c] USER → The working folder is: /workspace  Please perform the following task: list all files
[2025-02-07 14:30:01] [019c3759-4911-7974-87e2-b2535896279c] TOOL_CALL list_folder_content (path=/workspace)
[2025-02-07 14:30:01] [019c3759-4911-7974-87e2-b2535896279c] TOOL_RESULT list_folder_content (342 chars)
[2025-02-07 14:30:03] [019c3759-4911-7974-87e2-b2535896279c] ASSISTANT → I found the following files in your workspace: index.html, styles.css, main.js...
```

The conversation log uses a custom `ConversationLogFormatter` that strips the channel name, log level, and Monolog metadata, producing clean `[datetime] [conversationId] message` lines. Tool inputs are truncated to 100 characters; the assistant response is truncated to 300.

---

## 5. CLI Viewer (`mise run conversation-log`)

The `conversation-log` command is the primary way to consume these logs during development. It streams log entries in real time, `tail -f` style.

### 5.1 Modes

| Mode | Command | Log Source | Use Case |
|------|---------|------------|----------|
| **Wire** (default) | `mise run conversation-log <uuid>` | `llm-wire.log` | See raw HTTP traffic |
| **Human-readable** | `mise run conversation-log -H <uuid>` | `llm-conversation.log` | Quickly understand what the agent did |
| **All entries** | `mise run conversation-log --all` | `llm-wire.log` | Browse without a UUID filter |
| **HTML viewer** | `mise run conversation-log --generate-viewer <uuid>` | `llm-wire.log` | Generate an offline-browsable HTML file |

### 5.2 Typical Workflow

1. Open a conversation in the browser (e.g., `http://127.0.0.1:60916/en/conversation/019c3721-...`).
2. In a separate terminal, start the log viewer:
   ```bash
   mise run conversation-log 019c3721-2f76-743a-8c6b-61e2d662d0f3
   ```
3. Send a prompt in the browser. Watch the raw request/response/chunks scroll in the terminal.
4. To see a simplified view instead:
   ```bash
   mise run conversation-log -H 019c3721-2f76-743a-8c6b-61e2d662d0f3
   ```

### 5.3 Startup Check

The command checks whether `LLM_WIRE_LOG_ENABLED=1` is active inside the messenger container before streaming. If logging is not enabled, it exits with a clear error message explaining how to enable it. This prevents the confusing experience of staring at an empty stream.

### 5.4 How Streaming Works

Under the hood, the command runs:

```bash
docker compose exec -T messenger tail -n 1000 -F "var/log/llm-wire.log" | grep --line-buffered "<uuid>"
```

The `-F` flag handles log rotation. The `--line-buffered` flag on `grep` ensures entries appear immediately rather than being buffered. The initial `-n 1000` pre-scans recent entries so that if you start the viewer after the conversation has already produced some output, you see existing entries immediately.

---

## 6. HTML Viewer (`--generate-viewer`)

For post-hoc analysis, the command can generate a self-contained HTML file that embeds all wire log data and provides an interactive browser-based UI.

### 6.1 Generating a Viewer

```bash
mise run conversation-log --generate-viewer 019c3759-4911-7974-87e2-b2535896279c
```

This produces `conversation-log-019c3759-4911-7974-87e2-b2535896279c.html` in the project root. Open it in any browser -- it works fully offline with no dependencies.

### 6.2 How It Works

```
conversation-log.sh                    conversation-log-viewer-template.html
       │                                          │
       │  grep all lines for UUID                 │  Contains __LOG_DATA_B64__
       │  from llm-wire.log                       │  placeholder
       │          │                               │
       │          ▼                               │
       │  base64-encode the raw logs              │
       │          │                               │
       │          └───── inject into ─────────────┘
       │                 template
       │
       ▼
  conversation-log-<uuid>.html
  (self-contained, offline, client-side)
```

The shell script:
1. Extracts all wire log lines matching the conversation UUID.
2. Base64-encodes them (to avoid shell escaping issues with embedded JSON).
3. Splits the HTML template at the `__LOG_DATA_B64__` placeholder.
4. Writes head + base64 data + tail into the output file.

The JavaScript in the template:
1. Decodes the base64 data using `TextDecoder('utf-8')` for correct Unicode handling.
2. Parses each Monolog `LineFormatter` line, extracting datetime, message type, and nested JSON context.
3. Groups raw entries into logical "turns" (request-response-stream sequences).
4. Reassembles fragmented SSE stream chunks into complete assistant text and tool call structures.
5. Renders turns as interactive, expandable cards with full-text search.

### 6.3 Viewer Features

- **Turn-based grouping**: Each LLM API round-trip is shown as a card.
- **Request details**: Full request body (system prompt, messages, tools) with formatted JSON.
- **Assembled response**: The streaming chunks are reconstructed into the final assistant text.
- **Tool call visibility**: Tool call names, arguments, and results are shown inline.
- **Search**: Full-text search across all entries with match highlighting.
- **Expand/collapse**: Individual turns or all turns at once.
- **Dark theme**: Designed for extended reading sessions.

Generated viewer files are ignored by git (`/conversation-log-*.html` in `.gitignore`).

---

## 7. Monolog Configuration

Both channels are isolated from the main application log to avoid pollution and to keep the log files greppable.

### 7.1 Channel Isolation

In every environment (`dev`, `test`, `prod`), the `main` and `console` handlers explicitly exclude `llm_wire` and `llm_conversation`:

```yaml
channels: ["!event", "!doctrine", "!llm_wire", "!llm_conversation"]
```

Each channel has its own dedicated stream handler writing to its own file. The wire log uses Monolog's default `LineFormatter`; the conversation log uses the custom `ConversationLogFormatter`.

### 7.2 Processor Registration

The `LlmWireLogProcessor` is registered as a Monolog processor for **both** channels via service tags:

```yaml
App\LlmContentEditor\Infrastructure\WireLog\LlmWireLogProcessor:
    tags:
        - { name: monolog.processor, channel: llm_wire }
        - { name: monolog.processor, channel: llm_conversation }
```

This ensures both channels receive the `conversationId` and `workspaceId` in their `extra` data, which is what enables UUID-based filtering.

### 7.3 Environment Behavior

| Environment | Wire log file written? | Conversation log file written? | Notes |
|-------------|----------------------|-------------------------------|-------|
| `dev` | Yes (enabled by default) | Yes (enabled by default) | `.env.dev` sets `LLM_WIRE_LOG_ENABLED=1` |
| `test` | No | No | Disabled; no overhead in test suite |
| `prod` | Only if explicitly enabled | Only if explicitly enabled | Set `LLM_WIRE_LOG_ENABLED=1` in `.env.local` |

The Monolog handlers exist in all environments (so the channel routing is always correct), but no log entries are produced when the feature is disabled because the logging code paths are gated by the `$llmWireLogEnabled` flag in the facade and agent.

---

## 8. Component Reference

### 8.1 Infrastructure Classes

| Class | Responsibility |
|-------|---------------|
| `LlmWireLogMiddleware` | Static factory that builds a Guzzle `HandlerStack` with wire-logging middleware. Logs outgoing requests (method, URL, headers, body) and incoming responses (status, headers). For streaming responses, wraps the body in `LoggingStream`. |
| `LoggingStream` | PSR-7 `StreamInterface` decorator. Buffers bytes read from the underlying stream and emits each complete newline-delimited SSE line as a `← chunk` log entry. Flushes any remaining buffer on `close()`. Returns original data unmodified to the consumer. |
| `LlmWireLogProcessor` | Monolog `ProcessorInterface` that reads `conversationId` and `workspaceId` from `AgentExecutionContextInterface` and injects them into the log record's `extra` array. Shared by both channels. |
| `ConversationLogFormatter` | Custom Monolog `LineFormatter` with fixed format `[%datetime%] [%extra.conversationId%] %message%\n`. Falls back to an em-dash (`—`) when no conversation ID is present. |
| `LlmConversationLogObserver` | `SplObserver` attached to the NeuronAI agent. Translates `ToolCalling`, `ToolCalled`, and `AgentError` events into concise log messages. Truncates tool inputs to 100 characters. |

### 8.2 Configuration Files

| File | What it configures |
|------|--------------------|
| `.env` | `LLM_WIRE_LOG_ENABLED=0` (default off) |
| `.env.dev` | `LLM_WIRE_LOG_ENABLED=1` (on in dev) |
| `config/packages/monolog.yaml` | Channel definitions, handler routing, formatter binding |
| `config/services.yaml` | Processor tags, formatter registration, facade logger injection |

### 8.3 CLI Tool Files

| File | Purpose |
|------|---------|
| `.mise/tasks/conversation-log.sh` | CLI viewer script (streaming + HTML generation) |
| `.mise/tasks/conversation-log-viewer-template.html` | HTML/CSS/JS template for the self-contained viewer |

### 8.4 Test Coverage

| Test File | Tests |
|-----------|-------|
| `tests/Unit/LlmContentEditor/WireLog/LlmWireLogProcessorTest.php` | Processor enriches records with context IDs, handles missing context, preserves existing extra fields |
| `tests/Unit/LlmContentEditor/WireLog/LoggingStreamTest.php` | SSE line buffering, partial-read buffering, empty-line skipping, buffer flush on close, data passthrough |
| `tests/Unit/LlmContentEditor/ConversationLog/ConversationLogFormatterTest.php` | Output format, datetime format, fallback dash, no channel/level in output |
| `tests/Unit/LlmContentEditor/ConversationLog/LlmConversationLogObserverTest.php` | Tool call/result logging, input truncation, error logging, null/unknown event handling |

---

## 9. Data Flow: A Complete Turn

To tie everything together, here is what happens when a user sends "list all files" and the agent calls the `list_folder_content` tool:

```
User sends prompt
       │
       ▼
LlmContentEditorFacade::streamEditWithHistory()
       │
       ├── llm_conversation: "USER → The working folder is: /workspace ..."
       │
       ├── Creates agent with Guzzle wire-logging middleware
       ├── Attaches LlmConversationLogObserver
       │
       ▼
Agent sends HTTP request to OpenAI
       │
       ├── llm_wire: "→ request" {method, url, headers, body (with full messages array)}
       │
       ▼
OpenAI returns streaming response (tool call)
       │
       ├── llm_wire: "← response" {status: 200, headers}
       ├── llm_wire: "← chunk" {line: "data: {choices:[{delta:{tool_calls:...}}]}"}
       ├── llm_wire: "← chunk" ...
       ├── llm_wire: "← chunk" {line: "data: [DONE]"}
       │
       ▼
Agent executes list_folder_content tool
       │
       ├── llm_conversation: "TOOL_CALL list_folder_content (path=/workspace)"
       ├── (tool runs, returns file listing)
       ├── llm_conversation: "TOOL_RESULT list_folder_content (342 chars)"
       │
       ▼
Agent sends second HTTP request (with tool result)
       │
       ├── llm_wire: "→ request" {body includes tool_call_result message}
       │
       ▼
OpenAI returns streaming response (final text)
       │
       ├── llm_wire: "← response" {status: 200}
       ├── llm_wire: "← chunk" {line: "data: {choices:[{delta:{content:\"I found...\"}}]}"}
       ├── llm_wire: "← chunk" ...
       ├── llm_wire: "← chunk" {line: "data: [DONE]"}
       │
       ▼
Facade logs final response
       │
       ├── llm_conversation: "ASSISTANT → I found the following files in your workspace..."
       │
       ▼
Generator yields done chunk
```

The wire log shows two full HTTP round-trips. The conversation log shows four semantic lines. Both are filtered to the same conversation UUID.
