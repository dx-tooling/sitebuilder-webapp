#!/usr/bin/env bash
set -e

SUFFIX="${PB_WORKSPACE_ID:0:8}"

mise trust

cat > .env.local <<EOF
HOST_PROJECT_PATH="$(pwd)"
ETFS_PROJECT_NAME="sb-${SUFFIX}"
EOF

HOST_PROJECT_PATH="${HOST_PROJECT_PATH:-$(pwd)}" docker compose up --build -d

docker compose exec -T app composer install --no-interaction
docker compose exec -T app mise trust
docker compose exec -T app mise install
docker compose exec -T app mise exec node -- npm install --no-save
docker compose exec -T app php bin/console doctrine:database:create --if-not-exists
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app mise exec node -- php bin/console tailwind:build
docker compose exec -T app mise exec node -- php bin/console typescript:build
