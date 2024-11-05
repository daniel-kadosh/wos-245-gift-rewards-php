# https://github.com/thecodingmachine/docker-images-php
ARG PHP_EXTENSIONS="sqlite3 pdo_sqlite"
FROM thecodingmachine/php:8.3-v4-slim-apache

######## PHP + Composer config
# https://leafphp.dev/docs/introduction/installation.html
RUN composer global require leafs/mvc
RUN sudo ln -s /root/.composer/vendor/bin/leaf /usr/local/bin/leaf

# Extra config for PHP.ini
COPY ./php.ini /etc/php/8.3/cli/conf.d/99-wos245.ini
COPY ./php.ini /etc/php/8.3/apache2/conf.d/99-wos245.ini

######## Apache config
EXPOSE 80/tcp 443/tcp
ENV APACHE_CONFDIR                  /etc/apache2
ENV APACHE_ENVVARS                  $APACHE_CONFDIR/envvars
ENV ABSOLUTE_APACHE_DOCUMENT_ROOT   /var/www/public/
ENV APACHE_EXTENSIONS               "auth_digest ssl socache_shmcb"

# Container image just needs a bare minimum Apache config.
# This + other Apache configs / vhosts get re-injected at every container startup.
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www

CMD ["./docker-cmd.sh"]
