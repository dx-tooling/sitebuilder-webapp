#!/usr/bin/env bash

set -e

SCRIPT_FOLDER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

rm -f "$SCRIPT_FOLDER/../../.git/hooks/pre-commit"
ln -s "$SCRIPT_FOLDER/../git-hooks/pre-commit" "$SCRIPT_FOLDER/../../.git/hooks"
chmod 0755 "$SCRIPT_FOLDER/../../.git/hooks/pre-commit"
