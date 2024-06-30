#!/bin/bash

ENV=production
CMD=""

function usage() {
    echo "Usage:
$0 -a|-o|-r [-p|-d]
-a stArt WOS container
-o stOp WOS container
-r Rebuild WOS container
-p set to Production environment
-d set to Development environment
"
    exit 1
}

OPSTRING=":aorpd"
while getopts ${OPSTRING} opt; do
    case ${opt} in
        a)  CMD=wos-start
            ;;
        o)  CMD=wos-stop
            ;;
        r)  CMD=wos-rebuild
            ;;
        p)  ENV=production
            ;;
        d)  ENV=dev
            ;;
        ?)  echo "Invalid option: -${OPTARG}"
            usage
            ;;
    esac
done

if [[ -z "${CMD}" ]] then
    echo "Error: need a command"
    usage
fi

function wos-start() {
    echo "Starting with ${ENV} environment"
    set -x
    cd /home/divergent/wos-245-gift-rewards-php
    cp -f .env.${ENV} .env
    sudo docker compose up --detach
}

function wos-stop() {
    echo "Stopping"
    set -x
    CONTAINER=`sudo docker ps | grep wos-245-gift-rewards-php | head -1 | awk '{print $1}'`
    sudo docker kill ${CONTAINER}
}

function wos-rebuild() {
    echo "Rebuilding with ${ENV} environment"
    set -x
    #cd /home/divergent/wos-245-gift-rewards-php
    composer update
    cp -f .env.${ENV} .env
    sudo docker compose build --no-cache
}

$CMD
