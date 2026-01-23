#  Devbook

How do I solve recurring tasks and problems during development?


## Building the frontend

- `mise run frontend`


## Updating all dependencies

- `mise run composer update --with-dependencies`
- `mise run npm update`
- `mise run console importmap:update`


## Changing the database schema with migrations

- Create new or edit existing entities
- Run `mise run console make:migration`


## Connect to the local database

- `mise run db`
