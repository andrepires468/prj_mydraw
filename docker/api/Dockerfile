# ==============================================================================
# mydraw_api — Lumen 10 (PHP 8.2 FPM)
# ==============================================================================
FROM php:8.2-fpm-alpine

# Dependências de sistema
RUN apk add --no-cache \
        bash \
        git \
        curl \
        unzip \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        autoconf \
        g++ \
        make \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
        intl \
        gd \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del --no-cache autoconf g++ make linux-headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuário não-root alinhado ao UID do host (ajuste se necessário)
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} mydraw \
    && adduser -D -u ${UID} -G mydraw -s /bin/bash mydraw \
    && mkdir -p /var/www/html \
    && chown -R mydraw:mydraw /var/www/html

WORKDIR /var/www/html

USER mydraw

EXPOSE 9000

CMD ["php-fpm", "-F"]
