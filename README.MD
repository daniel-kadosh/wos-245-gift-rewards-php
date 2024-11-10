# README

WOS-245 Gift Rewards & User database (PHP version 1.7.1)

## App Info
Application is initially based on this work:
https://github.com/Nico31300/wos-245-gift-rewards

Website/API to send Whiteout Survival gift codes to members of an alliance in State #245.
Main index page has links to list and manage player IDs.
Has a "hidden" menu (/admin URL) to help manage Apache digest auth users

Written in PHP 8 with the Leaf microframework:
https://leafphp.dev/docs/introduction/

DISCLAIMER: This work follows many best-practices but takes a lot of shortcuts
because it is just not that important and I'm lazy ;-)

## Installation
* Clone from Github: https://github.com/daniel-kadosh/wos-245-gift-rewards-php
* Host OS: Install Docker
* Run `wos.sh -r` to build docker + application
* Create login credentials for Apache:
** Run `wos.sh -a` to launch the container
** Run `wos.sh -u USERNAME` to create one (or more) users
NOTE: A single digest auth file is used, so all users there can manage all of
the subdomains hosted. Security hole for now, may fix later

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
* Database: SQlite3, with Laravel's Eloquent DB imported with Leaf
* HTTP request library: Guzzle https://docs.guzzlephp.org/en/stable/

## Configuration
Docs from the Leaf PHP framework: https://leafphp.dev/docs/config/
* `.env.*` files define the Leaf app environment. NOTE: `.env` gets overwritten with some wos.sh commands.
* `app/routes/_app.php` Defines URL routes
* `app/controllers/WosController.php` Has the entire application logic
* `wos.sh` script is a simple tool to build and manage the docker container.
Run without parameters to get usage details.
* `docker/Dockerfile` For the container build, exposing Apache on port 80 and 443
* `docker/000-default.conf` Defines the apache vhost config
* `docker/php.ini` Has extra PHP configs for both CLI and running under Apache

Runtime files you need to create:
* `./apache-auth` Apache digest auth user file. Use `wosh.sh -u USERNAME` to create at least 1 user,
then you can manage using the "hidden" `/admin` menu from your web browser.
The `/admin/*` hidden menu relies on digest auth being the mechanism to handle users of the app.
* `wos245/valhalla245.duckdns.org/*` SSL certificates, installed manually
* `wos245/tea245.duckdns.org/*` SSL certificates, installed manually

Runtime files created automatically
* `wos245-[3-letter alliance]/gift-rewards.db` SQLite3 database file
* `wos245-[3-letter alliance]/wos_controller_*.log` App log file

Configure alliances to support in .env.prod (copied to .env at startup) with 2 parallel
lists of 3-letter name + long name
* ALLIANCES=VHL,Tea
* ALLIANCES_LONG=Valhalla,TeaAndBiscuits

NOTE: this Git project's entire directory structure is mounted in the docker image as `/var/www`,
and file changes in the host OS immediately take effect under the running container.

## Database creation
`wos.sh -r` will create an empty SQlite3 db schema automatically if it doesn't exist.

To empty the database, remove `wos245-[3-letter alliance]/gift-rewards.db` and re-start the application with
`wos.sh -a [-p|-d]`.

## Database initialization
Normal startup with `wos.sh -a` will run all DB migration scripts under
`app/database/migrations/` on every single database configured

## Deployment instructions
Run `wos.sh -a -p` to start with production environment, or `wos.sh -a -d` for dev/debug environment.
If container doesn't seem to respond after starting in production mode, either look for docker's log files
or start it in dev mode where it runs in the foreground and you can see exactly how things are starting up.

By default, digest auth is enabled in Apache and to create user(s) use `wosh.sh -u USERNAME`

Run `wos-sh -o` to stop the docker container.
