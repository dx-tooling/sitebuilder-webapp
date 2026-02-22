#!/usr/bin/env bash
set -e

# Derive a short unique suffix from the workspace ID provided by ProductBuilder.
# This ensures that concurrent agent sessions on the same project get fully
# isolated Docker Compose stacks (containers, volumes, networks).
SUFFIX="${PB_WORKSPACE_ID:0:8}"

# Export ETFS_PROJECT_NAME as a real environment variable. Docker Compose uses
# real env vars for variable substitution in docker-compose.yml (container_name,
# volume names, network names). It does NOT read .env.local — only .env is loaded
# by default. Exporting here overrides the hardcoded value in .env.
export ETFS_PROJECT_NAME="sb-${SUFFIX}"

# Also export HOST_PROJECT_PATH so the bind-mount source in docker-compose.yml
# resolves to the workspace directory on the host (required for DooD path
# consistency — see productbuilder-webapp multilevel-docker-architecture.md).
export HOST_PROJECT_PATH="${HOST_PROJECT_PATH:-$(pwd)}"

mise trust

# Write .env.local for Symfony. The Symfony runtime reads .env and .env.local at
# boot, with .env.local taking precedence. This gives the PHP application the
# correct ETFS_PROJECT_NAME and HOST_PROJECT_PATH values at runtime.
cat > .env.local <<EOF
HOST_PROJECT_PATH="${HOST_PROJECT_PATH}"
ETFS_PROJECT_NAME="${ETFS_PROJECT_NAME}"
EOF

docker compose up -d
sleep 5

# COMPOSER_NO_UNZIP=1 forces Composer to use PHP's ZipArchive instead of the
# system unzip binary. The workspace is bind-mounted from the macOS host, whose
# case-insensitive filesystem causes unzip to fail on packages containing files
# that differ only in case. ZipArchive handles this transparently.
docker compose exec -T -e COMPOSER_NO_UNZIP=1 app composer install --no-interaction

mise run in-app-container mise trust
mise run in-app-container mise install
mise run npm install --no-save
mise run console doctrine:database:create --if-not-exists
mise run console doctrine:migrations:migrate --no-interaction
mise run frontend
