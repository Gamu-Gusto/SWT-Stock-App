# Super Wheels and Tyres – production container (used by Render; works on any Docker host).
# Stage 1 builds the Vue frontend, stage 2 installs PHP dependencies, stage 3 is the runtime
# (PHP 8.3 + Apache). Node.js is not present in the final image.

# ---------------------------------------------------------------- 1. frontend assets
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public
# Fonts are downloaded here and self-hosted from public/build.
RUN npm run build

# ---------------------------------------------------------------- 2. PHP dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------------------------------------------------------------- 3. runtime
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libpq-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl zip gd bcmath exif opcache \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP: production defaults, hidden version, generous limits for spreadsheet imports and photos.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'expose_php=Off'; \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=16M'; \
      echo 'max_execution_time=120'; \
      echo 'date.timezone=Africa/Johannesburg'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=128'; \
    } > "$PHP_INI_DIR/conf.d/swt.ini"

# Apache: serve only public/, honour .htaccess, listen on the port Render provides ($PORT).
ENV PORT=10000
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers \
 && echo 'Listen ${PORT}' > /etc/apache2/ports.conf \
 && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf \
 && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/start.sh /usr/local/bin/swt-start
RUN chmod +x /usr/local/bin/swt-start \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000
CMD ["swt-start"]
