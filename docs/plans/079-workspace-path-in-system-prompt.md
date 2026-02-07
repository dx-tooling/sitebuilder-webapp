# Implementation Plan: Workspace path in system prompt (#79)

> **Issue:** [#79 — LLM loses workspace path after context-window compaction](https://github.com/dx-tooling/sitebuilder-webapp/issues/79)

## Problem

The workspace path is currently only in the **first user message** (“The working folder is: /workspace …”). When the context window is trimmed from the front, that message is dropped. The system prompt still says “The working folder path is given in the user's message,” but that message is gone, so the model asks the user for the path (e.g. on turn 64).

## Solution

Put the workspace path in the **system prompt** so it is never trimmed.

1. **AgentConfigDto**  
   Add optional `workingFolderPath: ?string` (e.g. `/workspace`). When set, the agent will append it to the system prompt.

2. **RunEditSessionHandler**  
   When building `AgentConfigDto`, pass `$session->getWorkspacePath()` (the path the agent sees is always `/workspace`; we already use that in the first user message).

3. **ContentEditorAgent**  
   Override `instructions()` to append a line like:  
   `WORKING FOLDER (use for all path-based tools): {workingFolderPath}`  
   when `workingFolderPath` is set. Use the path from `AgentConfigDto`.

4. **AgentConfigTemplate (PATH RULES)**  
   Update wording so the path is described as coming from the **system prompt** (and optionally the first user message), not only “in the user's message.”

5. **First user message (optional)**  
   We can keep prefixing the first user message with “The working folder is: /workspace” for redundancy, or remove it once the system prompt carries the path. Keeping it is harmless; removing it avoids duplicate info.

## Files to touch

- `src/LlmContentEditor/Facade/Dto/AgentConfigDto.php` — add `?string $workingFolderPath`
- `src/ChatBasedContentEditor/Infrastructure/Handler/RunEditSessionHandler.php` — pass workspace path into `AgentConfigDto`
- `src/LlmContentEditor/Domain/Agent/ContentEditorAgent.php` — append path to system prompt in `instructions()`
- `src/ProjectMgmt/Domain/ValueObject/AgentConfigTemplate.php` — PATH RULES: “given in the system prompt below (and in the first user message if present)”
- Call sites that construct `AgentConfigDto` without a session (e.g. controller for context dump, CLI) — pass `null` for `workingFolderPath`
