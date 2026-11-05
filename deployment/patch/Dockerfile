FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY source/frontend/package*.json ./
RUN npm ci
COPY source/frontend/ ./
RUN VITE_API_URL=/api npm run build

FROM php:8.5-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libonig-dev libzip-dev libsodium-dev unzip \
    && docker-php-ext-install -j2 pdo_mysql mbstring intl zip bcmath sodium \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY source/backend/ ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
COPY --from=frontend /build/dist/ ./public/
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY start.sh /usr/local/bin/yodamen-start
RUN chmod -R a+rX /var/www/html \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs storage/app/private storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/yodamen-start
ENTRYPOINT ["/usr/local/bin/yodamen-start"]
CMD ["apache2-foreground"]
COPY verify-patch.php check-health.php ./
