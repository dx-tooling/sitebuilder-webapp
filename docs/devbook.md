#  Devbook

How do I solve recurring tasks and problems during development?


## Building the frontend

- `bash bin/build-frontend.sh`


## Updating all dependencies

- `composer update --with-dependencies`
- `nvm use && npm update`
- `php bin/console importmap:update`


## Changing the database schema with migrations

- Create new or edit existing entities
- Run `php bin/console make:migration`


## Connect to the local database

- `bash bin/connect-to-db.sh`
