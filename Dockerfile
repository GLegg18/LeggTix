FROM php:8.4-cli-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring dom xml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000

FROM runtime AS test

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts

COPY . .
# A blank image-only env file avoids phpdotenv read warnings during PHPUnit.
RUN touch .env && composer dump-autoload --no-interaction --optimize

CMD ["php", "artisan", "test", "--compact"]

# Keep the default image used by docker-compose.yml as the bind-mounted runtime.
FROM runtime AS development
