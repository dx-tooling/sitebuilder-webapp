#!/usr/bin/env bash
#MISE description="Run all checks (build, quality, tests) that verify the correctness of the project"

set -e

mise run frontend
mise run quality
mise run tests
mise run tests:frontend
mise run tests:e2e
