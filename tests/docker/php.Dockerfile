# The PHP that runs the test suite inside the compose network.
# pdo_sqlite is part of the official image; pdo_mysql is added.
FROM php:8.5-cli

RUN docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
