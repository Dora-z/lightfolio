FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libsqlite3-dev unzip \
    && docker-php-ext-install mbstring pdo_sqlite \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV LIGHTFOLIO_STORAGE_DIR=/var/www/storage \
    LIGHTFOLIO_CONFIG_FILE=/var/www/storage/config.php

WORKDIR /var/www/html

COPY docker/apache-lightfolio.conf /etc/apache2/sites-available/000-default.conf
COPY docker/docker-entrypoint.sh /usr/local/bin/lightfolio-entrypoint
COPY . /var/www/html

RUN chmod +x /usr/local/bin/lightfolio-entrypoint \
    && mkdir -p /var/www/storage /var/www/html/uploads/previews \
    && chown -R www-data:www-data /var/www/storage /var/www/html/uploads /var/www/html/data

EXPOSE 80

ENTRYPOINT ["lightfolio-entrypoint"]
CMD ["apache2-foreground"]
