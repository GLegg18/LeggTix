FROM php:8.4-cli-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring dom xml pcntl \
    # Fetch separately to avoid PECL's redirect handling and retry transient failures.
    && curl --fail --show-error --silent --location \
        --proto '=https' --proto-redir '=https' \
        --connect-timeout 15 --max-time 60 \
        --retry 5 --retry-all-errors --retry-delay 2 --retry-max-time 180 \
        --output /tmp/redis-6.3.0.tgz https://pecl.php.net/get/redis-6.3.0.tgz \
    && echo '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5  /tmp/redis-6.3.0.tgz' | sha256sum --check --strict \
    && pecl install /tmp/redis-6.3.0.tgz \
    && docker-php-ext-enable redis \
    && rm -f /tmp/redis-6.3.0.tgz \
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
