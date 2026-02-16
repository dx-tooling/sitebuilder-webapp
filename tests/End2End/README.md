# End-to-end tests (Playwright)

Self-contained Playwright test suite for the SiteBuilder web app. No code is shared with the rest of the repository.

## Prerequisites

- Docker (stack must be runnable via `docker compose`)
- Node.js (for running Playwright on the host)

## Running e2e tests

From the **repository root**:

```bash
mise run tests:e2e
```

This will:

1. Start the app stack in Symfony **test** env (`docker-compose.yml` + `docker-compose.e2e.yml`)
2. Reset the test database (drop, create, migrate)
3. Run Playwright tests against `http://127.0.0.1:8080` (or `BASE_URL` if set)

## Options

- **`--no-start`** – Skip starting the stack. Only reset the test DB and run Playwright. Use when the e2e stack is already up.
- **`BASE_URL`** – Override base URL (default: `http://127.0.0.1:8080`).

Example:

```bash
BASE_URL=http://localhost:8080 mise run tests:e2e --no-start
```

## Running from this directory

If the stack is already up and the test DB is reset:

```bash
cd tests/End2End
npm install
BASE_URL=http://127.0.0.1:8080 npx playwright test
```

## Test user shortcut

To create a signed-up user without using the UI (e.g. for specs that need a logged-in user), run from the repo root:

```bash
docker compose -f docker-compose.yml -f docker-compose.e2e.yml exec -T app php bin/console app:e2e:create-user --email=e2e@example.com --password=secret
```

Then in tests, sign in via the sign-in page with those credentials.
