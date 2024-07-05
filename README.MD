# README

WOS-245 Gift Rewards (PHP version)

## App Info
Crude website/API to send Whiteout Survival gift codes to members of an alliance in State #245.
Main index page has links to list and manage player IDs.

Written in PHP 8 with the Leaf microframework:
https://leafphp.dev/docs/introduction/

Based on this work:
https://github.com/Nico31300/wos-245-gift-rewards

## Installation
* Clone from Github: https://github.com/daniel-kadosh/wos-245-gift-rewards-php
* Host OS: Install Docker, and optionally SQlite3
* Run `wos.sh -r` to build docker + application
* Create login credentials for Apache:
** Run `wos.sh -a` to launch the container
** Run `wos.sh -u USERNAME` to create one (or more) users

## System dependencies
Host OS:
* Docker
* SQlite3 optional, if you want to access the database from the host OS

Docker container:
* Base docker image is a minimalist PHP 8.3 + Apache `8.3-v4-slim-apache`
** https://github.com/thecodingmachine/docker-images-php
* Apache 4.2
* PHP 8.3
* PHP Composer
* SQLite3

App details:
* Framework: Leaf PHP microframework
* Database: SQlite3, with Laravel's Illuminate database management framework
* HTTP requests: Guzzle

## Configuration
Docs from the Leaf PHP framework: https://leafphp.dev/docs/config/
* `.env.*` files define the Leaf app environment. NOTE: `.env` gets overwritten with some wos.sh commands.
* `app/routes/_app.php` Defines URL routes
* `app/controllers/WosController.php` Has the entire application logic
* `wos.sh` script is a simple tool to build and manage the docker container.
Run without parameters to get usage details.
* `docker/Dockerfile` For the container build, exposing Apache on port 80 and 443
* `docker/000-default.conf` Defines the apache vhost config
* `docker/php.ini` Has extra PHP configs for both CLI and under Apache

Runtime files:
* `wos245/gift-rewards.db` SQLite3 database file
* `wos245/apache-auth` Apache digest auth user file
* `wos245/valhalla245.duckdns.org/*` SSL certificates

NOTE: this Git project's entire directory structure is mounted in the docker image as `/var/www`,
and file changes in the host OS immediately take effect under the running container.

## Database creation
`wos.sh -r` will create an empty SQlite3 db automatically if it doesn't exist.

To empty the database, remove `wos245/gift-rewards.db` and re-start the application.

## Database initialization
Normal startup with `wos.sh -a` will run all DB migration scripts under
`app/database/migrations/`

## Deployment instructions
Run `wos.sh -a -p` to start with production environment, or `wos.sh -a -d` for dev/debug environment.

By default, digest auth is enabled in Apache and to create user(s) use `wosh.sh -u USERNAME`

Run `wos-sh -o` to stop the docker container.
