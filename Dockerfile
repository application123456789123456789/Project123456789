# ── Stage 1: Composer dependencies ──────────────────────────
FROM composer:2.8 AS composer_stage

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ── Stage 2: PHP-FPM + Nginx Runtime ────────────────────────
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    autoconf \
    g++ \
    make \
    nginx \
    supervisor \
    gettext

RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    pcntl \
    bcmath

RUN pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make

RUN echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.max_children = 20" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.start_servers = 5" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.min_spare_servers = 2" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "pm.max_spare_servers = 10" >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --from=composer_stage /app/vendor ./vendor
COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Copy configs
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx/default.conf /etc/nginx/nginx-app.conf

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080
CMD ["/entrypoint.sh"]
