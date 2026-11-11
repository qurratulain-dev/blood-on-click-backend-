FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chmod -R 775 storage bootstrap/cache \
    && chmod +x docker-start.sh

ENV PORT=10000
EXPOSE 10000

CMD ["./docker-start.sh"]
