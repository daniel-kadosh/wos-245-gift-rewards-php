#!/bin/bash
set -x
cd /home/divergent/wos-245-gift-rewards-php
composer update
cp -f .env.production .env
sudo docker compose build --no-cache