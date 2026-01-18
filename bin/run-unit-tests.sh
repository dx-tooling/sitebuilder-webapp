#!/usr/bin/env bash

set -e

SCRIPT_FOLDER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

/usr/bin/env php "$SCRIPT_FOLDER/phpunit" "$SCRIPT_FOLDER/../tests/"
