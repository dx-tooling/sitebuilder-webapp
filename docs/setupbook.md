# Setupbook

How do I get a development environment for this application up and running?


## On macOS

- Ensure that `php --version` resolves to PHP 8.2.x
- Clone this repository
- cd into the cloned repo root folder
- Run `composer install`
- Run `nvm install`
- Run `npm install --no-save`
- Run `php bin/console importmap:install`
- Run `bash bin/install-git-hooks.sh`
- Run `php bin/console doctrine:database:create --if-not-exists`
- Run `php bin/console doctrine:migrations:migrate`
- Run `bash bin/build-frontend.sh`
- Run `symfony server:start`
