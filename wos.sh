#!/bin/bash

ENV=production
CMD=""
HTUSER=""

function usage() {
    echo "Usage:
$0 -a|-o|-r|(-u username) [-p|-d]
-r Rebuild/create WOS container

-a stArt WOS container
-o stOp WOS container

Container must be running for these tools:
-b start Bash shell inside running container, for debugging
-u create User (or change user's password) for Apache digest auth

Environment defaults to ${ENV}, and these only apply to -r and -a:
-p set to Production environment
-d set to Development environment
"
    exit 1
}

OPSTRING=":aorbpdu:"
while getopts ${OPSTRING} opt; do
    case ${opt} in
        a)  CMD=wos-start
            ;;
        o)  CMD=wos-stop
            ;;
        r)  CMD=wos-rebuild
            ;;
        b)  CMD=wos-bash
            ;;
        p)  ENV=production
            ;;
        d)  ENV=dev
            ;;
        u)  CMD=wos-user
            HTUSER=${OPTARG}
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

APACHE_AUTH_FILE=wos245/apache-auth
SQLITE_FILE=wos245/gift-rewards.db
DOCKER_APP_NAME=wos245-app

function wos-start() {
    echo "Starting with ${ENV} environment"
    if ! [ -f ${APACHE_AUTH_FILE} ]; then
        echo "***WARNING: Apache auth file ${APACHE_AUTH_FILE} doesn't exist,
        so after the container starts create at least 1 user:
        $0 -u USERNAME
"
    fi
    OPT=""
    if [[ "$ENV" == "production" ]]; then
        OPT="--detach"
    fi
    set -x
    # Ensure we have an SQLite3 database file
    touch ${SQLITE_FILE}
    chmod 666 ${SQLITE_FILE}
    cp -f .env.${ENV} .env
    sudo docker compose up --remove-orphans $OPT
}

function wos-stop() {
    echo "Stopping"
    set -x
    sudo docker compose down --remove-orphans ${DOCKER_APP_NAME}
}

function wos-rebuild() {
    echo "Rebuilding with ${ENV} environment"
    set -x
    cp -f .env.${ENV} .env
    # Docker build
    sudo docker compose build --no-cache
    # Bring up container
    sudo docker compose up --remove-orphans --detach ${DOCKER_APP_NAME}
    # PHP Composer build within the container
    sudo docker compose exec ${DOCKER_APP_NAME} composer update
    # Shut down again
    sudo docker compose down --remove-orphans ${DOCKER_APP_NAME}
}

function wos-bash() {
    echo "Starting Bash shell in running container"
    set -x
    sudo docker compose exec ${DOCKER_APP_NAME} bash
}

function wos-user() {
    echo "Creating user for Apache digest auth"
    OPT=""
    if [[ ! -f ${APACHE_AUTH_FILE} ]]; then
        OPT="-c"
    fi
    set -x
    sudo docker compose exec ${DOCKER_APP_NAME} htdigest ${OPT} ${APACHE_AUTH_FILE} wos245 ${HTUSER}
    echo "== Resulting Apache digest auth file:"
    cat ${APACHE_AUTH_FILE}
}

$CMD
