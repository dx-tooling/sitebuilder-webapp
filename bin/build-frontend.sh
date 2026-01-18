#!/usr/bin/env bash

set -e

SCRIPT_FOLDER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

rm -rf "$SCRIPT_FOLDER/../public/assets"
/usr/bin/env php "$SCRIPT_FOLDER/console" tailwind:build
/usr/bin/env php "$SCRIPT_FOLDER/console" asset-map:compile
