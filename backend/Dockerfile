FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl libpq-dev libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

ENV PORT=8000
EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force --no-interaction && php artisan db:seed --force --no-interaction && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
