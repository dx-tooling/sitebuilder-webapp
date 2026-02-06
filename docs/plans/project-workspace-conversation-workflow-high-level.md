# Project Workspace Conversation Workflow

We need to implement a workflow which ensures that SiteBuilder users can work on content projects in an structured manner.

For this, we are introducing new concepts and extend existing concepts.

## New concepts

### Projects

A project is the wrapper for an independent code repository on which users can work through SiteBuilder.
Every project is mapped to a git repository. Working on a project means checking out the underlying git repository into a SiteBuilder workspace, doing work within that workspace through a chain of one or more consecutive chat-based editor conversations, and finally putting the workspace into the review state.

### Workspaces

A workspace is the content of a project made available to work on via chat-based editor conversations.
Through workspace state, SiteBuilder ensures that there is only ever at most one active conversation that works on the workspace at any given time.


## Workflow

A typical workflow looks like this:

- A user decides that they want to work on the contents of project "foo".
- The system checks if a workspace for this project is already in place.
  - If not, the system creates a new workspace linked to the project, and triggers workspace setup
- The system checks if the workspace is available for an editor conversation by this user
  - If not, the system informs the user that the workspace is currently not available for a conversation
  - If yes, the system checks if the current user already has an open conversation for this workspace
    - If not, a new conversation is created and mapped to the workspace and this user, and the user is sent to the conversation UI
    - If yes, the user is sent into the existing open conversation 
    - The user works on the content through the chat
    - The user needs to actively close the conversation once their current work is done; until they do so, the workspace is not available for conversations by other users, only by the user that currently has the open conversation
    - The user can also decide to ask for a workspace review; as long as the workspace is in review, no conversation by any user is possible on that workflow
    - Review of a workspace happens outside of SiteBuilder; users with role "reviewer" can set a workspace back into status "merged" or "available_for_conversation"

## Workspace and conversation lifecycle

When a user wants to start a conversation on a project, and there is no workspace for this project, then
- A workspace is created, and set into status AVAILABLE_FOR_SETUP

When a user wants to start a conversation on a project, and there is a workspace for this project, and it is in status AVAILABLE_FOR_SETUP or MERGED, then
- The workspace is set into status IN_SETUP
- The local workspace folder is removed
- The git repository mapped to the project that is mapped to the workspace is cloned into the local workspace folder using the github token of the project
- A new branch, whose name has the format `<YYYY-MM-DD_HH-MM-SS>-usermailATdomainDOTtld-SHORTWORKSPACEID` (timestamp with underscore/hyphens for URL/shell safety, sanitized user email, short workspace ID), is created from the main branch
- The workspace is set into status AVAILABLE_FOR_CONVERSATION

When a user wants to start a conversation on a project, and succeeds to do so because its workspace is in status AVAILABLE_FOR_CONVERSATION, then
- The workspace is set into status IN_CONVERSATION
- A new conversation is created, mapped to the workspace, and set into status ONGOING

When a user wants to start a conversation on a project, and succeeds to do so because there is a workspace that is in status IN_CONVERSATION, and there is a conversation belonging to this user and workspace, and it is in status ONGOING, then
- The user is sent into this conversation

When the user is in a conversation, and changes the contents of the workspace, then
- Using the github token for the project of this workspace, the changes are committed onto the workspace branch, and the branch is pushed to the remote

When the user is in a conversation, and clicks "finish conversation", then
- Any uncommitted changes are committed and pushed to the workspace branch using the github token for the project of this workspace
- The conversation is set into status FINISHED
- The workspace is set into status AVAILABLE_FOR_CONVERSATION

When the user is in a conversation, and clicks "sent to review", then
- The conversation is set into status FINISHED
- The workspace is set into status IN_REVIEW
- Using the github token for the project of this workspace, the system checks if there is already a Pull Request for the workspace branch; if not, it is created

This workflow is meant to guarantee that at any point in time, there is always only one user who can work on a workspace.

## Problem handling

If any of the workspace setup steps fails (local folder cleanup, clone, checkout, branch creation) or git operations fail during conversation commits/pushes, the workspace is set into status PROBLEM, and any ongoing conversation is set into status FINISHED. Workspaces in status PROBLEM are not available for conversations until a user resets the workspace to AVAILABLE_FOR_SETUP.

## Review lifecycle details

Review happens outside of SiteBuilder. When reviewers finish, they set the workspace into a final state:
- If the changes are accepted, the workspace is set to MERGED
- If changes are requested, the workspace is set to AVAILABLE_FOR_CONVERSATION

No branch or workspace cleanup is required. The next conversation attempt will re-run setup as needed, which implicitly refreshes the workspace contents.

## Workspace state transition table

| From status | Trigger | To status | Notes |
| --- | --- | --- | --- |
| (none) | Create workspace | AVAILABLE_FOR_SETUP | Created for a project that has no workspace yet |
| AVAILABLE_FOR_SETUP | Start conversation on project | IN_SETUP | Begins setup (clone, checkout, branch) |
| MERGED | Start conversation on project | IN_SETUP | Re-initialize workspace from main |
| IN_SETUP | Setup succeeds | AVAILABLE_FOR_CONVERSATION | Workspace ready to be used |
| IN_SETUP | Setup fails | PROBLEM | Requires manual reset |
| AVAILABLE_FOR_CONVERSATION | Start conversation (no open convo) | IN_CONVERSATION | Creates new conversation |
| IN_CONVERSATION | Start conversation (same user, ongoing) | IN_CONVERSATION | User is sent into existing conversation |
| IN_CONVERSATION | Finish conversation | AVAILABLE_FOR_CONVERSATION | Conversation finished |
| IN_CONVERSATION | Send to review | IN_REVIEW | Conversation finished and PR ensured |
| IN_CONVERSATION | Git operation fails | PROBLEM | Conversation finished, requires reset |
| IN_REVIEW | Reviewer sets merged | MERGED | Review completed and merged |
| IN_REVIEW | Reviewer unlocks | AVAILABLE_FOR_CONVERSATION | Continue work without new setup |
| PROBLEM | Reset by user | AVAILABLE_FOR_SETUP | Re-run setup |

## Implementation plan

This plan reflects the current facade skeletons in `WorkspaceMgmt`, `ProjectMgmt`, and `ChatBasedContentEditor`, plus the existing conversation/edit-session flow in `ChatBasedContentEditor` and the tooling/LLM integration in `LlmContentEditor`/`WorkspaceTooling`.

1. **Align data model with the workflow**
   - Add `PROBLEM` to `WorkspaceStatus`.
   - Introduce `Project` and `Workspace` entities in their verticals:
     - `Project`: `id`, `name`, `gitUrl`, `githubToken`.
     - `Workspace`: `id`, `projectId`, `status`, `branchName`, `workspacePath`, timestamps.
   - Extend `ChatBasedContentEditor\Domain\Entity\Conversation` to reference `workspaceId` and `userId`, and persist a conversation status (`ONGOING`/`FINISHED`) instead of only keeping sessions.
   - Add Doctrine migrations for new tables/columns and required indexes (workspace status, conversation workspace+user, etc.).

2. **Update facade DTOs and interfaces (domain-centric)**
   - `WorkspaceInfoDto` should expose `id`, `projectId`, `status`, and `branchName` (and `workspacePath` only if needed in other verticals).
   - Add `ConversationInfoDto` in `ChatBasedContentEditor\Facade\Dto` with `id`, `workspaceId`, `userId`, `status`.
   - `WorkspaceMgmtFacadeInterface` should expose:
     - `getCurrentWorkspace(string $projectId): ?WorkspaceInfoDto`
     - `isConversationPossible(string $workspaceId): bool`
     - `resetWorkspaceToAvailableForSetup(string $workspaceId): bool`
   - `ChatBasedContentEditorFacadeInterface` should expose:
     - `startConversation(string $workspaceId, string $userId): ConversationInfoDto`
     - `finishConversation(string $conversationId): void` (forces final commit + push)
     - `sendToReview(string $conversationId): void`
     - `getOpenConversationForUser(string $workspaceId, string $userId): ?ConversationInfoDto`

3. **User-facing UI and use cases**
   - **Authentication required**: all workspace/project actions require a logged-in account session.
   - **Project management UI**:
     - Create project (name, git URL, GitHub token).
     - Edit project attributes (update token, URL, name).
     - List projects and show current workspace status.
   - **Workspace conversation UI**:
     - Start or resume conversation for a selected project.
     - Show status banners for `IN_REVIEW` and `PROBLEM`, with reset action for `PROBLEM`.
     - Finish conversation and send to review actions.
   - **Reviewer use case**:
     - Simple review actions to set workspace to `MERGED` or `AVAILABLE_FOR_CONVERSATION`.

4. **Workspace management services**
   - Add a `WorkspaceSetupService` in `WorkspaceMgmt\Domain\Service` that performs setup and transitions `AVAILABLE_FOR_SETUP|MERGED -> IN_SETUP -> AVAILABLE_FOR_CONVERSATION` or `PROBLEM`.
   - Add a `WorkspaceStatusGuard` to enforce the transition table (used by all façade methods).
   - Provide a `WorkspaceConversationGuard` to ensure single active conversation per workspace.

5. **Infrastructure services for “dirty work”**
   - Create three `WorkspaceMgmt\Infrastructure` services with plain PHP and direct process execution (no agent involvement):
     - **LocalFilesystemService**: remove/create workspace folder; filesystem ops; execute shell commands inside the workspace root.
     - **GitWorkspaceService**: clone, checkout, create branch, status, commit, push (via git CLI or a PHP git library).
     - **GitHubService**: find or create PRs using project `githubToken` (via GitHub API).
   - Wire these into `WorkspaceSetupService` and conversation completion hooks.

6. **Controller + facade integration**
   - Replace the direct `EntityManager` usage in `ChatBasedContentEditorController` with the new facades.
   - Conversation start:
     - Fetch or create workspace for project.
     - If status is `AVAILABLE_FOR_SETUP` or `MERGED`, trigger setup.
     - If `AVAILABLE_FOR_CONVERSATION`, create a new conversation and set `IN_CONVERSATION`.
     - If `IN_CONVERSATION`, resume the existing ongoing conversation for the same user.
     - If `IN_REVIEW` or `PROBLEM`, deny start and surface a user message (and allow reset for `PROBLEM`).
   - Add endpoints/actions to finish conversation and send to review.

7. **Git operations + status updates**
   - In `RunEditSessionHandler`, after a successful edit session, use `GitWorkspaceService` to check for changes and commit/push; on failure, set workspace to `PROBLEM` and finish the conversation.
   - On `finishConversation`, force a final commit/push even if there was no recent edit session.
   - On `sendToReview`, ensure a PR exists (GitHub service) and move workspace to `IN_REVIEW`.

8. **Config + paths**
   - The current `chat_based_content_editor.workspace_root` parameter should move to `WorkspaceMgmt` as the workspace root setting used by setup and tooling services.
   - `Conversation` should derive workspace path from its `Workspace` relation rather than storing a path as a standalone string.

9. **Concurrency, security, and roles**
   - Use DB transactions or locks to enforce “single active conversation per workspace”.
   - Ensure only the conversation owner can finish or send to review.
   - Allow any authenticated user to reset a `PROBLEM` workspace to `AVAILABLE_FOR_SETUP` (per requirement).

10. **Testing strategy and reducing brittleness**
   - Keep pure workflow logic in small services (guards, orchestration, status transitions) and unit-test those with no IO.
   - Introduce adapter interfaces for external dependencies:
     - `FilesystemAdapterInterface`, `CommandRunnerInterface`, `GitAdapterInterface`, `GitHubAdapterInterface`.
   - Provide **local stand-ins** for tests:
     - In-memory or temp-directory filesystem adapter that records operations.
     - Fake command runner that returns scripted outputs for git commands.
     - Fake GitHub adapter that stores PRs in memory.
   - In production, wire real implementations (native filesystem + `Process` for commands, GitHub API client).
   - Add a thin integration test suite that runs against the real adapters only when explicitly enabled (env flag), so CI remains fast and deterministic.

11. **Tests**
   - Unit tests for state transitions and guards (`PROBLEM`, reset, review exits).
   - Unit tests for orchestration with fake adapters (success and failure paths).
   - Integration tests for conversation start/finish/review flows using fakes, plus optional live tests for real git/GitHub adapters.
