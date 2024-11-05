#!/bin/bash
# This is the "entrypoint" script run on initial container startup.
set -x
# Re-inject any updated Apache configs
sudo cp /var/www/docker/00*.conf /etc/apache2/sites-available/

# Run all Leaf's DB migration scripts and on success launch Apache
php leaf db:migrate -vvv && \
apache2-foreground
