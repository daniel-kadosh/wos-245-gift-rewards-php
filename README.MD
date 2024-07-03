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
Clone from Github: https://github.com/daniel-kadosh/wos-245-gift-rewards-php

Install Docker, PHP 8.3 and Composer.  Optional: SQlite3

Run `wos.sh -r` to build docker + application.

Further help:
https://leafphp.dev/docs/introduction/installation.html

## System dependencies
Docker, PHP 8.3, Composer, SQLite3.

Leaf PHP microframework, using Laravel's Illuminate database management.
Guzzle is used to make HTTP requests to the WOS API.

Base docker image is a minimalist PHP 8.3 + Apache `8.3-v4-slim-apache`
https://github.com/thecodingmachine/docker-images-php

## Configuration
* `.env.*` files define the Leaf app environment. `.env` gets overwritten when running `wos.sh -a [-p|d]`.
* `app/routes/_app.php` Defines URL routes
* `app/controllers/WosController.php` Has the entire application logic
* `wos.sh` script is a simple tool to build and manage the docker container,
and will print out usage with no parameters.
* `docker/Dockerfile` For the container build, exposing Apache on port 80
* `docker/000-default.conf` Defines the apache vhost config
* `docker/php.ini` Has extra PHP configs for both CLI and under Apache

For more info from the Leaf PHP framework:
https://leafphp.dev/docs/config/

## Database creation
`wos.sh -r` will create an empty SQlite3 db automatically if it doesn't exist.

Remove ./wos245/gift-rewards.db and re-run `wos.sh -r` to empty the database.

## Database initialization
Normal startup with `wos.sh -a` will run all DB migration scripts under
`app/database/migrations/`

## Deployment instructions
Run `wos.sh -a -p` to start with production environment, or `wos.sh -a -d` for dev/debug).

Run `wos-sh -o` to stop docker.
