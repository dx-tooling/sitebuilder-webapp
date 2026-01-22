---
name: Project Workspace Workflow
overview: Implement the complete project-workspace-conversation workflow including entities, facades, infrastructure services for git/GitHub operations, and UI for project management, conversation lifecycle, and reviewer actions.
todos:
  - id: phase1-entities
    content: "Phase 1: Create Project and Workspace entities, extend Conversation, add PROBLEM status, create fresh migration"
    status: pending
  - id: phase2-dtos
    content: "Phase 2: Update facade DTOs and interfaces (WorkspaceMgmtFacade, ProjectMgmtFacade - cross-vertical only)"
    status: pending
  - id: phase3-adapters
    content: "Phase 3: Create infrastructure adapters (Git CLI, GitHub API, Filesystem)"
    status: pending
  - id: phase4-domain
    content: "Phase 4: Create domain services (WorkspaceSetupService, WorkspaceStatusGuard, WorkspaceGitService)"
    status: pending
  - id: phase5-facades
    content: "Phase 5: Implement facades (ProjectMgmtFacade, WorkspaceMgmtFacade) and internal domain services (ProjectService, WorkspaceService, ConversationService)"
    status: pending
  - id: phase6-controllers
    content: "Phase 6: Create/update controllers (ProjectController, ChatBasedContentEditorController, ReviewerController)"
    status: pending
  - id: phase7-templates
    content: "Phase 7: Create UI templates for projects, conversation status, and reviewer dashboard"
    status: pending
  - id: phase8-config
    content: "Phase 8: Update configuration (workspace_root parameter, security roles)"
    status: pending
  - id: phase9-tests
    content: "Phase 9: Add unit tests for guards and services, integration tests for flows"
    status: pending
---

# Project-Workspace-Conversation Workflow Implementation

## Overview

Implement the workflow from [project-workspace-conversation-workflow.md](docs/plans/project-workspace-conversation-workflow.md) that ensures structured content editing through projects, workspaces, and conversations with proper git integration.

---

## Phase 1: Data Model and Entities

### 1.1 Add PROBLEM status to WorkspaceStatus

Update [WorkspaceStatus.php](src/WorkspaceMgmt/Facade/Enum/WorkspaceStatus.php):

```php
case PROBLEM = 6;
```

### 1.2 Create Project Entity in ProjectMgmt

Create `src/ProjectMgmt/Domain/Entity/Project.php`:

- `id` (UUID)
- `name` (string)
- `gitUrl` (string) - e.g., `https://github.com/org/repo.git`
- `githubToken` (string) - plaintext for simplicity
- `createdAt` (DateTimeImmutable)

### 1.3 Create Workspace Entity in WorkspaceMgmt

Create `src/WorkspaceMgmt/Domain/Entity/Workspace.php`:

- `id` (UUID)
- `projectId` (string) - references Project
- `status` (WorkspaceStatus)
- `branchName` (string, nullable) - set during setup
- `createdAt` (DateTimeImmutable)
- Derived property: `workspacePath` = `<workspace_root>/<workspace_id>/`

### 1.4 Extend Conversation Entity

Modify [Conversation.php](src/ChatBasedContentEditor/Domain/Entity/Conversation.php):

- Add `workspaceId` (string) - references Workspace
- Add `userId` (string) - references AccountCore
- Add `status` (ConversationStatus: ONGOING/FINISHED)
- Remove `workspacePath` (derived from workspace)

### 1.5 Database Migration

Create a fresh migration that:

1. Creates `projects` table
2. Creates `workspaces` table with FK to projects
3. Drops existing conversation/edit_session/conversation_message tables
4. Creates fresh `conversations` table with workspace_id, user_id, status
5. Recreates `edit_sessions` and related tables

---

## Phase 2: Facade DTOs and Interfaces

**Key principle**: Facades expose only what OTHER verticals need. Internal operations use domain services directly.

### 2.1 Update WorkspaceInfoDto

Extend [WorkspaceInfoDto.php](src/WorkspaceMgmt/Facade/Dto/WorkspaceInfoDto.php):

```php
public function __construct(
    public string          $id,
    public string          $projectId,
    public string          $projectName,  // included so consumers don't need ProjectMgmtFacade
    public WorkspaceStatus $status,
    public ?string         $branchName,
    public string          $workspacePath,
)
```

### 2.2 Update WorkspaceMgmtFacadeInterface

Update [WorkspaceMgmtFacadeInterface.php](src/WorkspaceMgmt/Facade/WorkspaceMgmtFacadeInterface.php).

**Used by ChatBasedContentEditor** for workspace operations during conversation lifecycle:

```php
interface WorkspaceMgmtFacadeInterface
{
    // Query operations
    public function getWorkspaceForProject(string $projectId): ?WorkspaceInfoDto;
    
    // Lifecycle operations (called by ChatBasedContentEditor)
    public function ensureWorkspaceReadyForConversation(string $projectId): WorkspaceInfoDto;
    public function transitionToInConversation(string $workspaceId): void;
    public function transitionToAvailableForConversation(string $workspaceId): void;
    public function transitionToInReview(string $workspaceId): void;
    
    // Git operations (called by ChatBasedContentEditor after edits)
    public function commitAndPush(string $workspaceId, string $message): void;
    public function ensurePullRequest(string $workspaceId): string; // returns PR URL
}
```

Note: `ensureWorkspaceReadyForConversation` combines create-if-missing + setup-if-needed + return workspace. This is the single entry point for ChatBasedContentEditor.

### 2.3 Update ProjectMgmtFacadeInterface

Update [ProjectMgmtFacadeInterface.php](src/ProjectMgmt/Facade/ProjectMgmtFacadeInterface.php).

**Used by WorkspaceMgmt** for git operations during setup:

```php
interface ProjectMgmtFacadeInterface
{
    public function getProjectInfo(string $id): ProjectInfoDto;
    
    /** @return list<ProjectInfoDto> */
    public function getProjectInfos(): array;
}
```

Note: `createProject` is removed from facade - it's an internal operation within ProjectMgmt (controller → domain service).

### 2.4 ChatBasedContentEditorFacadeInterface

**No cross-vertical consumers** - leave empty or remove entirely.

The current empty interface can stay for future use, but conversation operations (start, finish, sendToReview) are internal to ChatBasedContentEditor:

- Controller calls internal domain services
- Domain services call WorkspaceMgmtFacade for workspace transitions

### 2.5 Internal DTOs (not on facades)

Create internal DTOs within each vertical as needed:

- `ChatBasedContentEditor/Domain/Dto/ConversationInfoDto.php` - for internal use only

---

## Phase 3: Infrastructure Services

### 3.1 Git Adapter Interface and Implementation

Create `src/WorkspaceMgmt/Infrastructure/Adapter/GitAdapterInterface.php`:

```php
interface GitAdapterInterface
{
    public function clone(string $repoUrl, string $targetPath, string $token): void;
    public function checkoutNewBranch(string $workspacePath, string $branchName): void;
    public function hasChanges(string $workspacePath): bool;
    public function commitAll(string $workspacePath, string $message): void;
    public function push(string $workspacePath, string $branchName, string $token): void;
}
```

Create `src/WorkspaceMgmt/Infrastructure/Adapter/GitCliAdapter.php` - uses shell Process to run git commands.

### 3.2 GitHub Adapter Interface and Implementation

Create `src/WorkspaceMgmt/Infrastructure/Adapter/GitHubAdapterInterface.php`:

```php
interface GitHubAdapterInterface
{
    public function findPullRequestForBranch(string $owner, string $repo, string $branchName, string $token): ?string;
    public function createPullRequest(string $owner, string $repo, string $branchName, string $title, string $body, string $token): string;
}
```

Create `src/WorkspaceMgmt/Infrastructure/Adapter/GitHubApiAdapter.php` - uses Symfony HttpClient to call GitHub REST API.

### 3.3 Filesystem Adapter Interface and Implementation

Create `src/WorkspaceMgmt/Infrastructure/Adapter/FilesystemAdapterInterface.php`:

```php
interface FilesystemAdapterInterface
{
    public function removeDirectory(string $path): void;
    public function createDirectory(string $path): void;
    public function exists(string $path): bool;
}
```

Create `src/WorkspaceMgmt/Infrastructure/Adapter/LocalFilesystemAdapter.php`.

---

## Phase 4: Domain Services

### 4.1 WorkspaceSetupService

Create `src/WorkspaceMgmt/Domain/Service/WorkspaceSetupService.php`:

- Orchestrates: remove folder -> clone -> checkout branch -> update status
- On failure: set workspace to PROBLEM
- Branch naming: `ws-<first8chars-of-workspace-id>-<YYYYMMDD-HHMMSS>`

### 4.2 WorkspaceStatusGuard

Create `src/WorkspaceMgmt/Domain/Service/WorkspaceStatusGuard.php`:

- Validates all status transitions per the state table
- Throws exception on invalid transition

### 4.3 WorkspaceGitService

Create `src/WorkspaceMgmt/Domain/Service/WorkspaceGitService.php`:

- `commitAndPush(Workspace, message)` - commit changes and push
- `ensurePullRequest(Workspace)` - find or create PR

---

## Phase 5: Facade and Domain Service Implementations

### 5.1 ProjectMgmtFacade

Create `src/ProjectMgmt/Facade/ProjectMgmtFacade.php`:

- Implements **read-only** operations for other verticals
- `getProjectInfo()` and `getProjectInfos()` only
- Uses EntityManager to query Project entities

### 5.2 ProjectMgmt Domain Service (internal)

Create `src/ProjectMgmt/Domain/Service/ProjectService.php`:

- Full CRUD operations for projects
- Used directly by ProjectController (same vertical)
- NOT exposed via facade

### 5.3 WorkspaceMgmtFacade

Create `src/WorkspaceMgmt/Facade/WorkspaceMgmtFacade.php`:

- Implements operations needed by ChatBasedContentEditor
- Delegates to internal domain services
- Uses ProjectMgmtFacadeInterface to get project details for git operations
- Uses DB transactions/locks for concurrency safety on status transitions

### 5.4 WorkspaceMgmt Domain Services (internal)

- `WorkspaceService` - workspace CRUD, used by facade and ReviewerController
- `WorkspaceSetupService` - setup orchestration
- `WorkspaceStatusGuard` - transition validation
- `WorkspaceGitService` - commit/push/PR operations

### 5.5 ChatBasedContentEditor Domain Service (internal)

Create `src/ChatBasedContentEditor/Domain/Service/ConversationService.php`:

- Conversation CRUD operations (start, finish, sendToReview)
- Uses WorkspaceMgmtFacadeInterface for workspace transitions
- Used directly by ChatBasedContentEditorController (same vertical)
- NOT exposed via facade (no other vertical needs it)

---

## Phase 6: Controller Integration

### 6.1 Project Management Controller

Create `src/ProjectMgmt/Presentation/Controller/ProjectController.php`:

- Uses **internal** `ProjectService` (same vertical, no facade)
- Uses `WorkspaceMgmtFacadeInterface` to get workspace status for display
- Routes:
  - `GET /projects` - list projects with workspace status
  - `GET /projects/new` - create project form
  - `POST /projects` - create project
  - `GET /projects/{id}/edit` - edit project form
  - `POST /projects/{id}` - update project

### 6.2 Update ChatBasedContentEditorController

Modify [ChatBasedContentEditorController.php](src/ChatBasedContentEditor/Presentation/Controller/ChatBasedContentEditorController.php):

- Uses **internal** `ConversationService` for conversation operations (same vertical)
- Uses `WorkspaceMgmtFacadeInterface` for workspace info and git operations
- Require logged-in user (inject Security to get current user)
- Routes:
  - `GET /projects/{projectId}/conversation` - start/resume conversation
  - `GET /conversation/{id}` - show conversation
  - `POST /conversation/{id}/run` - run edit session
  - `POST /conversation/{id}/finish` - finish conversation
  - `POST /conversation/{id}/send-to-review` - send to review
  - `POST /workspace/{id}/reset` - reset PROBLEM workspace
- Display status banners for IN_REVIEW, PROBLEM

### 6.3 Reviewer Controller

Create `src/WorkspaceMgmt/Presentation/Controller/ReviewerController.php`:

- Uses **internal** `WorkspaceService` (same vertical, no facade)
- Routes:
  - `GET /review` - list workspaces in IN_REVIEW status
  - `POST /review/{workspaceId}/merge` - set to MERGED
  - `POST /review/{workspaceId}/unlock` - set to AVAILABLE_FOR_CONVERSATION
- Requires ROLE_REVIEWER

---

## Phase 7: UI Templates

### 7.1 Project List Page

Create `src/ProjectMgmt/Presentation/Resources/templates/project_list.twig`:

- Table of projects with name, git URL, workspace status
- "Start Working" button per project
- "New Project" button

### 7.2 Project Form Page

Create `src/ProjectMgmt/Presentation/Resources/templates/project_form.twig`:

- Form fields: name, git URL, GitHub token
- Create/Update button

### 7.3 Update Conversation UI

Modify [chat_based_content_editor.twig](src/ChatBasedContentEditor/Presentation/Resources/templates/chat_based_content_editor.twig):

- Add status banner (PROBLEM with reset, IN_REVIEW with message)
- Add "Finish Conversation" button
- Add "Send to Review" button
- Show project/workspace context

### 7.4 Reviewer Page

Create `src/WorkspaceMgmt/Presentation/Resources/templates/reviewer_dashboard.twig`:

- List of workspaces in IN_REVIEW
- Per workspace: project name, branch name, PR link, action buttons

---

## Phase 8: Configuration

### 8.1 Move workspace_root parameter

Move from `chat_based_content_editor.workspace_root` to `workspace_mgmt.workspace_root` in [services.yaml](config/services.yaml).

### 8.2 Add ROLE_REVIEWER

Ensure security config recognizes ROLE_REVIEWER for the reviewer pages.

---

## Phase 9: Testing

### 9.1 Unit Tests

- `WorkspaceStatusGuardTest` - test all valid/invalid transitions
- `WorkspaceSetupServiceTest` - test with fake adapters

### 9.2 Integration Tests

- Test conversation start/finish/review flow with fakes
- Test git adapter error handling sets PROBLEM status

---

## Architecture Diagram

```mermaid

flowchart TB

subgraph ProjectMgmt [ProjectMgmt Vertical]

PC[ProjectController]

PS[ProjectService]

PMF[ProjectMgmtFacade]

PE[Project Entity]

PC --> PS

PS --> PE

PMF --> PE

end

subgraph WorkspaceMgmt [WorkspaceMgmt Vertical]

RC[ReviewerController]

WS[WorkspaceService]

WSS[WorkspaceSetupService]

WSG[WorkspaceStatusGuard]

WGS[WorkspaceGitService]

WMF[WorkspaceMgmtFacade]

WE[Workspace Entity]

RC --> WS

WMF --> WS

WMF --> WSS

WMF --> WGS

WS --> WSG

WS --> WE

WSS --> WE

WGS --> WE

end

subgraph ChatBasedContentEditor [ChatBasedContentEditor Vertical]

CC[ChatBasedContentEditorController]

CS[ConversationService]

CE[Conversation Entity]

CC --> CS

CS --> CE

end

subgraph Infrastructure [Infrastructure Adapters]

GA[GitCliAdapter]

GHA[GitHubApiAdapter]

FA[FilesystemAdapter]

end

%% Cross-vertical facade calls

CS -.->|facade| WMF

PC -.->|facade| WMF

WMF -.->|facade| PMF

WSS --> GA

WSS --> FA

WGS --> GA

WGS --> GHA