# Setupbook

How do I get a development environment for this application up and running?


## On macOS

- Ensure that Docker Desktop and mise-en-place, from https://mise.jdx.dev, are installed
- Clone this repository
- cd into the cloned repo root folder
- Run `mise trust`
- Run `docker compose up --build -d`
- Run `docker compose exec -ti app composer install`
- Run `mise run in-app-container mise trust`
- Run `mise run in-app-container mise install`
- Run `mise run npm install --no-save`
- Run `mise run console doctrine:database:create`
- Run `mise run console doctrine:migrations:migrate --no-interaction`
- Run `mise run frontend`
- Run `mise run quality`
- Run `mise run tests`
- Run `mise run browser`
