#!/usr/bin/env bash

set -e

SCRIPT_FOLDER="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

if [[ "$1" != "" ]]; then
    ENV=$1
else
    ENV="test"
fi

echo "resetting ${ENV}-env"

APP_ENV="${ENV}"
source "${SCRIPT_FOLDER}/_init.sh"

rm -rf var/cache

mysql -h"${DATABASE_HOST}" -u"${DATABASE_USER}" -p"${DATABASE_PASSWORD}" -e "DROP DATABASE IF EXISTS ${DATABASE_DB};"
mysql -h"${DATABASE_HOST}" -u"${DATABASE_USER}" -p"${DATABASE_PASSWORD}" -e "CREATE DATABASE ${DATABASE_DB};"

echo "starting migrations..."
/usr/bin/env php "${SCRIPT_FOLDER}/console" doctrine:migrations:migrate --env "${ENV}" --no-interaction --quiet

echo "done"
