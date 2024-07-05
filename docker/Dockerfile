# https://github.com/thecodingmachine/docker-images-php
FROM thecodingmachine/php:8.3-v4-slim-apache

######## PHP + Composer config
RUN sudo apt-get update && sudo apt-get install -y --no-install-recommends \
    php8.3-sqlite3 sqlite3

RUN composer global require leafs/cli
RUN sudo ln -s /root/.composer/vendor/bin/leaf /usr/local/bin/leaf

# Extra config for PHP.ini
COPY ./php.ini /etc/php/8.3/cli/conf.d/99-wos245.ini
COPY ./php.ini /etc/php/8.3/apache2/conf.d/99-wos245.ini

RUN sudo apt-get autoremove -y \
    && sudo apt-get clean \
    && sudo rm -rf /var/lib/apt/lists/* /tmp/*

######## Apache config
EXPOSE 80/tcp 443/tcp
ENV APACHE_CONFDIR                  /etc/apache2
ENV APACHE_ENVVARS                  $APACHE_CONFDIR/envvars
ENV ABSOLUTE_APACHE_DOCUMENT_ROOT   /var/www/public/
ENV APACHE_EXTENSIONS               "auth_digest ssl socache_shmcb"

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www

CMD ["./docker-cmd.sh"]
