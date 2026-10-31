# ==============================================================================
# mydraw_api — produção (Nginx + PHP-FPM na porta 80)
#
# Easypanel:
#   Caminho de Build: /apps/mydraw_api
#   Arquivo: Dockerfile
#   Porta do serviço: 80
# ==============================================================================
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        nginx \
        bash \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        libwebp \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libwebp-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
        intl \
        gd \
        opcache \
    && apk del .build-deps \
    && rm -f /etc/nginx/http.d/default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-autoloader \
        --no-interaction \
        --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -rf /root/.composer /usr/bin/composer \
    && mkdir -p storage/app \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-mydraw.ini
COPY docker/zz-fpm.conf /usr/local/etc/php-fpm.d/zz-mydraw.conf
COPY docker/entrypoint.sh /usr/local/bin/mydraw-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/mydraw-entrypoint \
    && chmod +x /usr/local/bin/mydraw-entrypoint

EXPOSE 80

CMD ["/usr/local/bin/mydraw-entrypoint"]
