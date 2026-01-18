#!/usr/bin/env bash

set -e

SCRIPT_FOLDER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

# See https://github.com/doctrine/migrations/issues/1406
# for the reason why we need to run this with --skip-sync for now
/usr/bin/env php "$SCRIPT_FOLDER/console" doctrine:schema:validate -v --skip-sync
/usr/bin/env php "$SCRIPT_FOLDER/console" doctrine:migrations:up-to-date

/usr/bin/env php "$SCRIPT_FOLDER/php-cs-fixer.php" fix
/usr/bin/env php "$SCRIPT_FOLDER/../vendor/bin/phpstan" --memory-limit=1024M

pushd "$SCRIPT_FOLDER/../"
  . "$HOME/.nvm/nvm.sh"
  nvm use
  /usr/bin/env npm run prettier:fix
  /usr/bin/env npm run lint
popd
