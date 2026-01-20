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


## Chat-based content editor

The chat-based content editor needs a workspace root path for file edits.

- Set `CHAT_EDITOR_WORKSPACE_ROOT` in `.env` to an absolute directory path (e.g. `/path/to/your/content-project`). The path must exist. If unset, the run endpoint will require a workspace path in the form.
- The path chosen (from the env or the form) must be under `CHAT_EDITOR_WORKSPACE_ROOT` when that variable is set; otherwise any existing directory is allowed.
