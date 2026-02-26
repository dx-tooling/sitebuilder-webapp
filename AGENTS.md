## PLAN FORMAT

EARS (Easy Approach to Requirements Syntax) is used in plans for specifying *system behavior* (requirements), not for describing the agent's implementation to-do list.

Use the structure below for code-change plans.

### REQUIREMENTS FORMAT (EARS)

Write compact, testable requirements about the system/component under change (not the agent). Name the system explicitly (e.g. `InkNewWizard`, `bep new`, `WizardState`).

- Ubiquitous (always true): `The <system> shall <response>.`
- State-driven: `While <precondition(s)>, the <system> shall <response>.`
- Event-driven: `When <trigger>, the <system> shall <response>.`
- Optional feature/scope: `Where <feature/scope applies>, the <system> shall <response>.`
- Unwanted behavior: `If <unwanted condition>, then the <system> shall <mitigation>.`
- Complex: `While <precondition(s)>, when <trigger>, the <system> shall <response>.`

Practical rules:

- Use requirement IDs (`R1`, `R2`, ...) so implementation and verification can reference them.
- Prefer observable behavior and invariants; avoid file/function names unless they are part of the external contract.

### IMPLEMENTATION PLAN FORMAT

Describe *how* you'll satisfy the requirements as concrete steps (agent actions), chunked into small git-committable units when appropriate.

- Size the steps to the change: use as few steps as needed for small fixes, and break larger changes into multiple git-committable chunks.
- Keep one concrete outcome per step (code change, test addition, verification, or user checkpoint).
- Include a USER checkpoint step for major or risky changes, consistent with the workflow above.

### VERIFICATION FORMAT

Include explicit checks that map back to the requirements.

- Each verification item should reference one or more requirement IDs (`R#`) and name the check (`npm test`, `npm run build`, or targeted manual validation).

Template (shape only):

- Requirements:
- `R1: When <trigger>, the <system> shall <response>.`
- `R2: While <state>, the <system> shall <response>.`
- Implementation:
- `S1: <edit(s) that satisfy R1/R2>.`
- `S2: USER checkpoint: review/commit chunk 1.`
- Verification:
- `V1 (R1,R2): npm test`

## Cursor Cloud specific instructions

### Architecture

SiteBuilder is a Symfony 7.4 / PHP 8.4 web app with a TypeScript/Stimulus frontend. It runs inside Docker containers (app, mariadb, nginx, messenger). See `docs/archbook.md` for vertical architecture details and `docs/frontendbook.md` for frontend conventions.

### Running the dev environment

All commands go through `mise run` (see `.cursor/rules/07-workflow.mdc` for the full task list). Key commands:

- `mise run setup` — idempotent full bootstrap (builds containers, installs deps, migrates DB, builds frontend, runs quality + tests)
- `mise run quality` — lint/format checks (required before commit)
- `mise run tests` — PHP tests (architecture, unit, integration, application)
- `mise run tests:frontend` — Vitest frontend tests
- `mise run frontend` — build frontend assets (Tailwind + TypeScript via SWC)

### Docker-in-Docker (Cloud VM specific)

The Cloud VM is itself a container inside a Firecracker VM. Docker must be installed with `fuse-overlayfs` storage driver and `iptables-legacy`. After installing Docker, run `sudo chmod 666 /var/run/docker.sock` so commands work without `sudo`. The update script handles starting `dockerd` if it's not already running.

### Composer private repositories

The `composer.json` references private GitHub VCS repos (`dx-tooling/etfs-*`). Composer needs a GitHub token: pass it via `COMPOSER_AUTH` env var or run `composer config --global github-oauth.github.com "$(gh auth token)"` inside the app container before `composer install`. The update script handles this automatically.

### Nginx port

The nginx container binds to a random host port (`127.0.0.1::80`). Find the assigned port with `docker compose ps` and look at the nginx PORTS column.

### `.env.local`

Must contain `HOST_PROJECT_PATH="/workspace"`. The setup script creates this if missing. This value is used by the messenger service for Docker-in-Docker volume mounts (not critical for basic dev).
