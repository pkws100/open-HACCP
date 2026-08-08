FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    --ignore-platform-req=ext-pdo_mysql

FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libfreetype6-dev libjpeg62-turbo-dev libonig-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/haccp-entrypoint
COPY . ./
COPY --from=dependencies /app/vendor ./vendor

RUN chmod +x /usr/local/bin/haccp-entrypoint \
    && mkdir -p /var/www/html/.runtime /var/lib/haccp-exports \
    && chown -R www-data:www-data /var/www/html/.runtime /var/lib/haccp-exports

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/haccp-entrypoint"]
CMD ["apache2-foreground"]
