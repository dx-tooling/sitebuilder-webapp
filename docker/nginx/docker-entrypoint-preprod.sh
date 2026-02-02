#!/bin/sh
set -e

# Execute the original nginx entrypoint
exec /docker-entrypoint.sh "$@"
