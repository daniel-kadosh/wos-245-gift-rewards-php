#!/bin/bash
set -x
php leaf db:migrate -vvv && \
apache2-foreground
