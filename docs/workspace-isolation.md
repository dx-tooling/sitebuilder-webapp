# Workspace Isolation

This document explains how workspace isolation is implemented in the sitebuilder application to ensure agents operating on workspaces cannot access files outside their designated workspace boundaries.

## Overview

The application uses a **two-layer isolation approach**:

1. **Path Validation Layer** - Validates all file operations to ensure paths stay within workspace boundaries
2. **Containerized Shell Execution** - Runs shell commands in isolated Docker containers with only the workspace mounted

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Messenger Container                         │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │ WorkspaceToolingFacade                                    │  │
│  │  ├─ SecureFileOperationsService (path validation)         │  │
│  │  │     └─ FileOperationsService (actual file I/O)         │  │
│  │  └─ IsolatedShellExecutor (containerized execution)       │  │
│  │        └─ DockerExecutor (Docker API)                     │  │
│  └───────────────────────────────────────────────────────────┘  │
│                              │                                  │
│                              ▼                                  │
│          ┌───────────────────────────────────────┐              │
│          │ Docker Socket (/var/run/docker.sock)  │              │
│          └───────────────────────────────────────┘              │
└──────────────────────────────│──────────────────────────────────┘
                               │
                               ▼
              ┌─────────────────────────────────────┐
              │ Ephemeral Agent Container           │
              │  - Project's configured image       │
              │  - Only workspace directory mounted │
              │  - Destroyed after command          │
              └─────────────────────────────────────┘
```

## Layer 1: Path Validation

### SecurePathResolver

Located at `src/WorkspaceTooling/Infrastructure/Security/SecurePathResolver.php`

This service validates all file paths before any operation:

- **Canonicalizes paths** - Resolves `.`, `..`, and symbolic links to absolute paths
- **Validates workspace boundaries** - Ensures resolved paths are under the workspace root
- **Detects escape attempts** - Blocks path traversal attacks like `../../etc/passwd`

### SecureFileOperationsService

Located at `src/WorkspaceTooling/Infrastructure/Security/SecureFileOperationsService.php`

A decorator that wraps the library's `FileOperationsService`:

```php
// All file operations go through validation first
public function getFileContent(string $pathToFile): string
{
    $this->validatePath($pathToFile);  // Throws if outside workspace
    return $this->inner->getFileContent($pathToFile);
}
```

Protected operations:
- `listFolderContent`
- `getFileContent`
- `getFileLines`
- `getFileInfo`
- `searchInFile`
- `replaceInFile`
- `writeFileContent`
- `createDirectory`

## Layer 2: Containerized Shell Execution

### Per-Project Docker Images

Each project can specify which Docker image to use for agent execution. This is configured in the project settings:

**Standard images available:**
- `node:22-slim` (default) - Node.js 22
- `node:20-slim` - Node.js 20
- `node:18-slim` - Node.js 18
- `python:3.12-slim` - Python 3.12
- `php:8.4-cli` - PHP 8.4
- Custom - Any Docker image name

### IsolatedShellExecutor

Located at `src/WorkspaceTooling/Infrastructure/Execution/IsolatedShellExecutor.php`

When a shell command needs to run:

1. Validates the working directory is within workspace root
2. Looks up the project's configured Docker image
3. Spawns an ephemeral container with only the workspace mounted
4. Executes the command
5. Returns output and destroys the container

### DockerExecutor

Located at `src/WorkspaceTooling/Infrastructure/Execution/DockerExecutor.php`

Builds and executes Docker commands:

```bash
docker run --rm -i \
    --name=sitebuilder-ws-my-project-019be640-a1b2c3d4-f8e9d0c1 \
    --workdir=/workspace \
    -v /var/www/public/workspaces/{id}:/workspace \
    --memory=512m \
    --cpus=1 \
    node:22-slim \
    sh -c "npm run build"
```

Key features:
- `--name` - Container named for easy identification (see naming convention below)
- `--rm` - Container is removed after execution
- `-v` mount - Workspace is always mounted as `/workspace` (path translated for Docker-in-Docker)
- `--workdir` - Set to `/workspace` (or subdirectory the agent specifies)
- `--memory=2g` / `--cpus=2` - Resource limits (2GB RAM, 2 CPUs for webpack builds)
- `NODE_OPTIONS=--max-old-space-size=1536` - Increased Node.js heap for webpack
- `--network=none` - Can optionally disable network (currently enabled for npm install)

### Agent Workspace Model

The agent always sees `/workspace` as its working directory. The actual filesystem path is:
1. Stored in the execution context when the agent session starts
2. Translated to a host path (for Docker-in-Docker)
3. Mounted as `/workspace` in the agent container

This simplifies the agent's view - it only needs to work with `/workspace` paths.

### Docker-in-Docker Path Translation

Since the messenger container runs Docker commands via the host Docker socket, volume mount
paths must be translated from container paths to host paths.

| Container Path | Host Path |
|----------------|-----------|
| `/var/www/public/workspaces/{id}` | `{HOST_PROJECT_PATH}/public/workspaces/{id}` |

**Configuration:**

Set `HOST_PROJECT_PATH` in your environment to the absolute path where the project is located on the host:

```bash
# In .env.local (not committed)
HOST_PROJECT_PATH=/home/user/git/sitebuilder-webapp

# Or when starting docker-compose
HOST_PROJECT_PATH=$(pwd) docker compose up -d
```

If not set, paths are passed through unchanged (works when running directly on host).

### Container Naming Convention

Containers are named: `sitebuilder-ws-{project}-{workspace}-{conversation}-{unique}`

| Component | Description | Example |
|-----------|-------------|---------|
| `sitebuilder-ws` | Fixed prefix | `sitebuilder-ws` |
| `{project}` | Normalized project name (max 20 chars) | `my-project` |
| `{workspace}` | First 8 chars of workspace ID | `019be640` |
| `{conversation}` | First 8 chars of conversation ID | `a1b2c3d4` |
| `{unique}` | Random suffix for concurrent runs | `f8e9d0c1` |

This naming makes it easy to identify which project, workspace, and conversation a container belongs to.

### Monitoring Agent Containers

To watch agent containers in real-time:

```bash
# Watch sitebuilder agent containers
watch -n 0.5 'docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}" | grep sitebuilder-ws'

# Filter by project name
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}" | grep "sitebuilder-ws-my-project"

# Or see all container events
docker events --filter 'event=start' --filter 'event=die' --filter 'name=sitebuilder-ws'
```

## Configuration

### Workspace Root

Defined in `config/services.yaml`:

```yaml
parameters:
    workspace_mgmt.workspace_root_default: "/var/www/public/workspaces"
    workspace_mgmt.workspace_root: "%env(default:workspace_mgmt.workspace_root_default:WORKSPACE_ROOT)%"
```

Can be overridden via `WORKSPACE_ROOT` environment variable.

### Service Wiring

The isolation services are wired in `config/services.yaml`:

```yaml
# Path validation
App\WorkspaceTooling\Infrastructure\Security\SecurePathResolver:
    arguments:
        - "%workspace_mgmt.workspace_root%"

# Secure file operations (decorator)
App\WorkspaceTooling\Infrastructure\Security\SecureFileOperationsService:
    arguments:
        - '@EtfsCodingAgent\Service\FileOperationsService'
        - '@App\WorkspaceTooling\Infrastructure\Security\SecurePathResolver'
        - "%workspace_mgmt.workspace_root%"

# Wire interface to secure implementation
EtfsCodingAgent\Service\FileOperationsServiceInterface:
    alias: App\WorkspaceTooling\Infrastructure\Security\SecureFileOperationsService

# Isolated shell executor
EtfsCodingAgent\Service\ShellOperationsServiceInterface:
    alias: App\WorkspaceTooling\Infrastructure\Execution\IsolatedShellExecutor
```

## Host Machine Requirements

### Docker Socket Access

The messenger container needs access to the Docker socket to spawn sibling containers:

```yaml
# docker-compose.yml
messenger:
    volumes:
        - /var/run/docker.sock:/var/run/docker.sock
```

### Docker CLI in Container

The application container needs the Docker CLI installed. This is done in the Dockerfile:

```dockerfile
# Install Docker CLI
RUN install -m 0755 -d /etc/apt/keyrings && \
    curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null && \
    apt-get update -y && \
    apt-get install -y docker-ce-cli
```

### Permissions

**On Linux (production):**

The user running PHP-FPM (typically `www-data`) needs permission to access the Docker socket. Options:

1. **Add to docker group** (simpler but less secure):
   ```bash
   usermod -aG docker www-data
   ```

2. **Docker socket proxy** (more secure):
   Use a proxy like [tecnativa/docker-socket-proxy](https://github.com/Tecnativa/docker-socket-proxy) to limit which Docker operations are allowed.

**On macOS (Docker Desktop):**

No special configuration needed. Docker Desktop handles socket permissions automatically.

### Image Availability

Agent images must be available on the host:

- **Standard images** (node, python, php) are pulled automatically from Docker Hub on first use
- **Custom images** must be:
  - Available in Docker Hub (public)
  - Or pulled manually before use
  - Or from a registry the host is authenticated to

## Security Considerations

### What's Protected

| Threat | Mitigation |
|--------|------------|
| Read files outside workspace | Path validation in `SecurePathResolver` |
| Write files outside workspace | Path validation in `SecureFileOperationsService` |
| Shell access to other paths | Container mounts only target workspace |
| Resource exhaustion | Memory and CPU limits on containers |
| Privilege escalation | Containers run as non-root by default |

### What's NOT Protected

- **Network access from containers** - Currently enabled for npm install, etc. Can be disabled with `--network=none` if not needed.
- **Malicious custom images** - Users can specify any Docker image. Consider an allowlist for production.
- **Docker escape vulnerabilities** - Standard Docker isolation applies. Not protected against kernel-level container escape exploits.

## Troubleshooting

### "Docker permission denied"

The messenger container can't access the Docker socket.

**Check socket mount:**
```bash
docker compose exec messenger ls -la /var/run/docker.sock
```

**On Linux, verify docker group:**
```bash
docker compose exec messenger id
# Should show docker group membership
```

### "Image not found"

The specified Docker image isn't available.

**Pull the image manually:**
```bash
docker pull node:22-slim
```

**Or check custom image name** - Ensure it includes the tag (e.g., `myimage:latest`).

### "Command timed out"

Shell commands have a 5-minute default timeout.

**For long-running builds:**
- Consider optimizing the build process
- Or increase timeout in `DockerExecutor` (not recommended for security)

### Path Traversal Exception

A file operation was blocked because the path resolved outside the workspace.

**Check the path** - Ensure it's relative to the workspace or an absolute path under the workspace root.

**Common causes:**
- Symbolic links pointing outside workspace
- Paths containing `../` that escape the workspace
- Absolute paths not under workspace root

## Testing

Unit tests for path validation are in `tests/Unit/WorkspaceTooling/SecurePathResolverTest.php`:

```bash
# Run isolation-related tests
docker compose exec app php vendor/bin/pest tests/Unit/WorkspaceTooling/
```

Tests cover:
- Valid paths within workspace
- Path traversal blocking (`../`)
- Absolute path validation
- Symlink escape detection
- Workspace ID extraction
