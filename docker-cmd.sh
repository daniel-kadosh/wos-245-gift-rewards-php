#!/bin/bash
# This is the "entrypoint" script run on initial container startup.
set -x

# Source in application's .env for ALLIANCES list and other config
cd /var/www
export $(grep -v '^#' .env | xargs)

# Put together all apache configs into 1 file, re-injecting into container
if [[ "${APP_ENV}" == "prod" ]] ; then
    CONF_FILE=/tmp/000-default.conf
    cat /var/www/docker/00*.conf > $CONF_FILE
else
    CONF_FILE=/var/www/docker/000-default.conf
fi
sudo cp $CONF_FILE /etc/apache2/sites-available/000-default.conf

# Run Leaf's DB migration scripts on all DBs and on success launch Apache
IFS=','
read -ra array <<< "$ALLIANCES"
echo "Setting up data dirs + DBs for $ALLIANCES"
for alliance in "${array[@]}"; do
    DIR=/var/www/wos245-"${alliance,,}"
    mkdir -p ${DIR}
    export DB_DATABASE=${DIR}/gift-rewards.db
    touch ${DB_DATABASE}
    # Seems all files need full permissions for web app :-(
    find ${DIR} -type d -exec chmod 0777 {} \;
    find ${DIR} -type f -exec chmod 0666 {} \;
    php leaf db:migrate -vvv
done && \
apache2-foreground
