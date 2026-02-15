#!/usr/bin/env bash
#MISE description="Run end-to-end tests (Playwright); boots stack in test env, resets DB, runs tests"
#USAGE flag "--no-start" help="Skip compose up; only reset test DB and run Playwright (stack must already be up with e2e override)"

set -e

COMPOSE_FILES="-f docker-compose.yml -f docker-compose.e2e.yml"
NO_START="${usage_no_start:-false}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"

echo
echo "End-to-end tests (Playwright)"
echo "BASE_URL=${BASE_URL}"
echo

if [ "${NO_START}" != "true" ]; then
    echo "Starting stack with e2e override..."
    docker compose $COMPOSE_FILES up -d
    echo "Waiting for MariaDB..."
    for i in $(seq 1 30); do
        if docker compose $COMPOSE_FILES exec -T mariadb mariadb -uroot -psecret -e "SELECT 1" 2>/dev/null; then
            break
        fi
        echo "Waiting for MariaDB... ($i/30)"
        sleep 2
    done
    echo "Waiting for app to respond on ${BASE_URL}..."
    for i in $(seq 1 30); do
        code=$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/" 2>/dev/null || true)
        if [ "$code" = "200" ] || [ "$code" = "302" ]; then
            break
        fi
        echo "Waiting for app... ($i/30)"
        sleep 2
    done
fi

echo "Resetting test database (drop, create, migrate)..."
docker compose $COMPOSE_FILES exec -T app php bin/console doctrine:database:drop --env=test --if-exists --force
docker compose $COMPOSE_FILES exec -T app php bin/console doctrine:database:create --env=test
docker compose $COMPOSE_FILES exec -T app php bin/console doctrine:migrations:migrate --no-interaction --env=test

echo "Installing Playwright dependencies (if needed)..."
(cd tests/End2End && npm install)
(cd tests/End2End && npx playwright install chromium 2>/dev/null || true)

echo "Running Playwright tests..."
(cd tests/End2End && BASE_URL="$BASE_URL" npx playwright test)

echo "E2E tests completed!"
