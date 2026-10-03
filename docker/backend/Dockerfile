FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY entrypoint.sh /usr/local/bin/backend-entrypoint
RUN chmod +x /usr/local/bin/backend-entrypoint

ENV COMPOSER_HOME=/tmp/composer \
    HOME=/tmp \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
EXPOSE 8000
