#!/bin/bash
# This is the "entrypoint" script run on initial container startup.
set -x
# Re-inject any updated Apache configs
sudo cp /var/www/docker/00*.conf /etc/apache2/sites-available/

# Run Leaf's DB migration scripts on all DBs and on success launch Apache
# Source in application's .env for ALLIANCES list and config
cd /var/www
export $(grep -v '^#' .env | xargs)
IFS=','
read -ra array <<< "$ALLIANCES"
echo "Setting up data dirs + DBs for $ALLIANCES"
for alliance in "${array[@]}"; do
    DIR=/var/www/wos245-"${alliance,,}"
    mkdir -p ${DIR}
    export DB_DATABASE=${DIR}/gift-rewards.db
    touch ${DB_DATABASE}
    # Seems all files need full permissions for web app :-(
    #find ${DIR} -type d -exec chmod 0755 {} \;
    chmod 666 ${DIR}/*
    find ${DIR} -type d -exec chmod 0777 {} \;
    php leaf db:migrate -vvv
done && \
apache2-foreground
