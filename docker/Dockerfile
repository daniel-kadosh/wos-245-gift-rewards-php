# https://github.com/thecodingmachine/docker-images-php
FROM thecodingmachine/php:8.3-v4-slim-apache

######## PHP + Composer config
RUN apt-get update && apt-get install -y --no-install-recommends \
    php8.3-sqlite3 sqlite3
#    php8.3-http

RUN composer install
RUN composer global require leafs/cli
RUN ln -s /root/.composer/vendor/bin/leaf /usr/local/bin/leaf

# PHP ini in:
# /etc/php/8.3/cli/php.ini
# /etc/php/8.3/cli/conf.d/*
# /etc/php/8.3/apache2/php.ini
# /etc/php/8.3/apache2/conf.d/*
COPY ./php.ini /etc/php/8.3/cli/conf.d/99-divergent.ini
COPY ./php.ini /etc/php/8.3/apache2/conf.d/99-divergent.ini

RUN apt-get autoremove -y \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/*

######## Apache config
EXPOSE 80
ENV APACHE_CONFDIR                  /etc/apache2
ENV APACHE_ENVVARS                  $APACHE_CONFDIR/envvars
ENV ABSOLUTE_APACHE_DOCUMENT_ROOT   /var/www/html/

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY ../public/.htaccess ../public/* ${ABSOLUTE_APACHE_DOCUMENT_ROOT}

WORKDIR /var/www

CMD ["apache2-foreground"]
