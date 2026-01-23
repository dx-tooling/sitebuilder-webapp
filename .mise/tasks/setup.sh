#!/usr/bin/env bash
#MISE description="Bootstrap local development environment"

set -e

if [ ! -f .env.local ]; then
    echo "HOST_PROJECT_PATH=$(pwd)" > .env.local
fi

HOST_PROJECT_PATH="${HOST_PROJECT_PATH:-$(pwd)}" /usr/bin/env docker compose up --build -d

mise run composer install
mise run in-app-container mise trust
mise run in-app-container mise install
mise run npm install --no-save
mise run console doctrine:database:create --if-not-exists
mise run console doctrine:migrations:migrate --no-interaction
mise run frontend
mise run quality
mise run tests
mise run browser
