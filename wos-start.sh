#!/bin/bash
set -x
cd /home/divergent/wos-245-gift-rewards-php
sudo docker ps
composer update
cp -f .env.production .env
sudo docker compose up &
