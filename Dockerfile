# Render web service: Vite assets and Laravel in one image.
# npm runs only while building the image, not when the container starts.

FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

FROM php:8.3-cli-alpine

# Official php:8.3-cli-alpine already enables ctype, curl, fileinfo, mbstring,
# openssl, pdo, phar, tokenizer, xml, and zip. pdo_mysql is the extra extension
# this app needs because production uses the MySQL driver.
RUN docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
    && rm -rf /root/.composer

COPY --from=assets /app/public/build ./public/build

RUN mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod 755 /entrypoint.sh

USER www-data

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
