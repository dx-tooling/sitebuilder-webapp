#!/usr/bin/env bash
#MISE description="Run frontend tests"
#USAGE flag "--watch" help="Run tests in watch mode"
#USAGE flag "--coverage" help="Run tests with coverage report"

set -e

WATCH="${usage_watch:-false}"
COVERAGE="${usage_coverage:-false}"

echo
echo "Running frontend tests..."

if [ "${WATCH}" == "true" ]; then
    mise run in-app-container mise exec node -- npm run test:watch
elif [ "${COVERAGE}" == "true" ]; then
    mise run in-app-container mise exec node -- npm run test:coverage
else
    mise run in-app-container mise exec node -- npm test
fi

echo "Frontend tests completed! ✨"
