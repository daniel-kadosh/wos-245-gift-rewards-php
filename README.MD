# README

WOS-245 Gift Rewards (PHP version)

## App Info
Crude website/API to send Whiteout Survival gift codes to members of an alliance in State #245.
Main index page has links to list and manage player IDs.
Written in PHP 8, and using the Leaf framework
https://leafphp.dev/docs/introduction/

## Installation
Clone from Github: https://github.com/daniel-kadosh/wos-245-gift-rewards-php
Install Docker, PHP 8.3 and Composer.  Optional: SQlite3
Run `wos.sh -r` to build docker + application.

For more info from the Leaf PHP framework:
https://leafphp.dev/docs/introduction/installation.html

## System dependencies
Docker, PHP 8.3, Composer, SQLite3
Application is based on the Leaf PHP microframework, which uses Laravel's Illuminate database management.
Guzzle is used to make HTTP requests to the WOS API.

## Configuration
.env file gets overriden with environment when running `wos.sh -a [-p|d]`.
Routes are defined in `app/routes/_app.php`
The entire application is in `app/controllers/WosController.php`

For more info from the Leaf PHP framework:
https://leafphp.dev/docs/config/

## Database creation
`wos.sh -r` will create an empty SQlite3 db automatically if it doesn't exist.
Remove ./wos245/gift-rewards.db and re-run `wos.sh -r` to empty the database.

## Database initialization
Normal startup with `wos.sh -a` will run all DB migration scripts under
`app/database/migrations/`

## Deployment instructions
Run `wos.sh -a -p` to start (-p=production environment, -d=dev/debug).
Run `wos-sh -o` to stop docker.
