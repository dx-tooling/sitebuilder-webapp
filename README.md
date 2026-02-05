# SiteBuilder Webapp

[![Build image](https://img.shields.io/github/actions/workflow/status/dx-tooling/sitebuilder-webapp/ci-build-app-image.yml?branch=main&label=Build%20image)](https://github.com/dx-tooling/sitebuilder-webapp/actions/workflows/ci-build-app-image.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/dx-tooling/sitebuilder-webapp/ci-quality.yml?branch=main&label=Quality)](https://github.com/dx-tooling/sitebuilder-webapp/actions/workflows/ci-quality.yml)
[![PHP tests](https://img.shields.io/github/actions/workflow/status/dx-tooling/sitebuilder-webapp/ci-tests-php.yml?branch=main&label=PHP%20tests)](https://github.com/dx-tooling/sitebuilder-webapp/actions/workflows/ci-tests-php.yml)
[![Frontend tests](https://img.shields.io/github/actions/workflow/status/dx-tooling/sitebuilder-webapp/ci-tests-frontend.yml?branch=main&label=Frontend%20tests)](https://github.com/dx-tooling/sitebuilder-webapp/actions/workflows/ci-tests-frontend.yml)
[![Frontend build](https://img.shields.io/github/actions/workflow/status/dx-tooling/sitebuilder-webapp/ci-build-frontend.yml?branch=main&label=Frontend%20build)](https://github.com/dx-tooling/sitebuilder-webapp/actions/workflows/ci-build-frontend.yml)

SiteBuilder is a Symfony web application for AI-assisted content editing. It gives non-engineering teams a normie-friendly way to edit web content projects like marketing pages, while still keeping engineers happy with Git-backed source control and clean PR workflows.

It is a [DX·Tooling](https://dx-tooling.org) project, sponsored by <a href="https://www.joboo.de"><img src="https://webassets.cdn.www.joboo.de/assets/images/joboo-inverted.png" alt="JOBOO! GmbH" height="18" /></a>

The app lets users set up content management projects backed by Git repositories, work inside isolated workspaces, and use chat-based workflows to edit files with an AI agent. It manages workspace lifecycle and review steps so changes can be inspected before merging.

<p align="center">
  <img src="https://dx-tooling.org/sitebuilder-assets/webapp-screenshots/web-versions/SiteBuilder-content-editor-landing-page-preview-and-GitHub-PR-for-changes-1600x987.png" alt="SiteBuilder: content editor, landing page result, and GitHub PR for the changes." width="640">
  <br>
  <em>Edit in chat → see the result → changes in a PR.</em>
  <br>
  <em>SiteBuilder is the content management tool that creators love to use and engineering loves to support.</em>
  <br>
  <a href="https://dx-tooling.org/sitebuilder-assets/sitebuilder-demo.mp4">Click to watch demo</a>
</p>

## Latest updates

- [UX Improvements: Conversation History and Reviewer Dashboard](https://dx-tooling.org/sitebuilder/blog/2026-01-26-ux-improvements.html)
- [Smarter Agent Tools: Better Commits and Preview Links](https://dx-tooling.org/sitebuilder/blog/2026-01-26-smarter-agent-tools.html)
- [January 2026 Updates: BYOK, Custom Agents, and More](https://dx-tooling.org/sitebuilder/blog/2026-01-26-january-updates.html)
- [Strong Workspace Isolation with Docker Containers](https://dx-tooling.org/sitebuilder/blog/2026-01-25-docker-workspace-isolation.html)
- [Deep Dive: Introducing SiteBuilder](https://dx-tooling.org/sitebuilder/blog/2026-01-25-deep-dive-introduction.html)

## What this repository contains

- A Symfony 7.4 backend that manages projects, workspaces, and conversations
- A Stimulus + TypeScript frontend for the editor UI and live interactions
- Dockerized execution for workspaces and background processing
- An opinionated vertical architecture with strict boundaries between features

## Tech stack

- PHP 8.4, Symfony 7.4, Doctrine ORM
- MariaDB 12
- TypeScript, Stimulus, Tailwind CSS, AssetMapper
- Docker + Docker Compose
- Mise for tool and task orchestration

## Architecture overview

The codebase is organized into verticals (feature modules) under `src/`. Each vertical has its own layers (`Domain`, `Facade`, `Infrastructure`, `Api`, `Presentation`). Cross-vertical communication happens only via Facades and DTOs. Client-side controllers live next to their vertical in `src/<Vertical>/Presentation/Resources/assets/controllers/`.

For details, see:
- `docs/archbook.md`
- `docs/frontendbook.md`
- `docs/workspace-isolation.md`

## Getting started (local development)

### Prerequisites

- Docker Desktop
- Mise (https://mise.jdx.dev)
- an unixoid system like Linux, macOS, or Windows Subsytem for Linux

### Setup

```bash
# 1) Trust mise in this repo
mise trust

# 2) Bootstrap the local environment
mise run setup
```

`mise run setup` will:
- Create `.env.local` with `HOST_PROJECT_PATH` if missing
- Build and start Docker containers
- Install PHP and frontend dependencies
- Create and migrate the database
- Build frontend assets
- Run quality checks and tests
- Open the app in your browser

## Common development tasks

All commands should be run via `mise run` so they execute inside the app container.

- Quality checks: `mise run quality`
- PHP tests: `mise run tests`
- Frontend tests (Vitest): `mise run tests:frontend`
- Build frontend assets: `mise run frontend`
- Symfony console: `mise run console <command>`
- Database shell: `mise run db`

More tasks are documented in:
- `docs/devbook.md`
- `docs/setupbook.md`

## Working with feature verticals

- Add new features as new verticals under `src/<FeatureName>/`.
- Expose cross-vertical behavior through Facade interfaces and DTOs.
- Keep client-side code inside the same vertical to maintain boundaries.

## Environment notes

- `.env` provides defaults; use `.env.local` for machine-specific overrides.
- `HOST_PROJECT_PATH` must be set for workspace execution (see setup).

## Additional docs

- `docs/archbook.md` — architecture and boundaries
- `docs/devbook.md` — recurring dev tasks
- `docs/setupbook.md` — full setup guide
- `docs/frontendbook.md` — frontend conventions
