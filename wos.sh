#!/bin/bash

APACHE_AUTH_FILE=apache-auth
DOCKER_APP_NAME=wos245-app
ENV=production
CMD=""
HTUSER=""
### NOT implementing realm stuff yet, to keep things simpler
HTREALM="wos245"

function usage() {
    echo "Usage:
$0 -a|-o|-r|(-u username) [-p|-d]
-r Rebuild/create WOS container

-a stArt WOS container
-o stOp WOS container

Container must be running for these tools:
-b start Bash shell inside running container, for debugging
-l show active logs (really for prod instance)
-u create User (or change user's password) for Apache digest auth
   ## NOT IMPLEMENTED: Requires -m REALM, as an alliance name like 'vhl'

Environment defaults to ${ENV}, and these only apply to -r and -a:
-p set to Production environment
-d set to Development environment
"
    exit 1
}

OPSTRING=":aorbpdlm:u:"
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
        l)  CMD=wos-logs
            ;;
        m)  CMD=wos-user
#            HTREALM="${OPTARG,,}"
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

# For Linux, docker commands run under sudo
# while for some things in Windows a "real tty" is needed
CMD_PREFIX="sudo"
if [[ `uname -o` == "Msys" ]]; then
    CMD_PREFIX="winpty"
fi

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
    cp -f .env.${ENV} .env
    ${CMD_PREFIX} docker compose up --remove-orphans $OPT
}

function wos-stop() {
    echo "Stopping"
    set -x
    ${CMD_PREFIX} docker compose down --remove-orphans ${DOCKER_APP_NAME}
}

function wos-logs() {
    PS_PREFIX=${CMD_PREFIX}
    if [[ "${PS_PREFIX}" == 'winpty' ]]; then
        PS_PREFIX=''
    fi
    CONTAINER=$(${PS_PREFIX} docker ps | grep leafphp-wos245 | awk "-F " '{print $1}')
    ${CMD_PREFIX} docker logs -f ${CONTAINER}
}

function wos-rebuild() {
    echo "Rebuilding with ${ENV} environment"
    set -x
    cp -f .env.${ENV} .env
    # Docker build
    ${CMD_PREFIX} docker compose build --no-cache
    # Bring up container
    ${CMD_PREFIX} docker compose up --remove-orphans --detach ${DOCKER_APP_NAME}
    # PHP Composer build within the container
    ${CMD_PREFIX} docker compose exec ${DOCKER_APP_NAME} composer update
    # Shut down again
    ${CMD_PREFIX} docker compose down --remove-orphans ${DOCKER_APP_NAME}
}

function wos-bash() {
    echo "Starting Bash shell in running container"
    set -x
    ${CMD_PREFIX} docker compose exec ${DOCKER_APP_NAME} bash
}

function wos-user() {
    echo "Creating user for Apache digest auth"
    if [[ -z "${HTUSER}" || -z "${HTREALM}" ]]; then
        echo "ABORT: Need both '-u USER' and '-l REALM' (alliance 3-letter)"
        exit -1
    fi

    OPT=""
    if [[ ! -f ${APACHE_AUTH_FILE} ]]; then
        OPT="-c"
    fi
    set -x
    ${CMD_PREFIX} docker compose exec ${DOCKER_APP_NAME} htdigest ${OPT} ${APACHE_AUTH_FILE} ${HTREALM} ${HTUSER}
    echo "== Resulting Apache digest auth file:"
    cat ${APACHE_AUTH_FILE}
}

$CMD
