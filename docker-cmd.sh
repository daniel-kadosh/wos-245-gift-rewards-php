#!/bin/bash
# This is the "entrypoint" script run on initial container startup.
#
# Actions:
# Run all Leaf's DB migration scripts and on success launch Apache
set -x
php leaf db:migrate -vvv && \
apache2-foreground
