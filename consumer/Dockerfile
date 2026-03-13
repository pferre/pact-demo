FROM php:8.3-fpm-alpine AS app_base

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    zip \
    unzip \
    git \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    libffi-dev

# Install PHP extensions
RUN docker-php-ext-install \
    intl \
    opcache \
    zip \
    mbstring \
    ffi

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ─────────────────────────────────────────────
# DEV TARGET
# ─────────────────────────────────────────────
FROM app_base AS app_dev

# Install Xdebug — isolate build deps into a virtual package so they
# can be removed afterwards, keeping the image lean.
# linux-headers is required by Xdebug on Alpine; without it pecl fails.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .build-deps

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/app.ini

# Copy app source
COPY . /app

RUN composer install --no-interaction --optimize-autoloader 2>/dev/null || true

# Install pact-standalone for pact-broker CLI
RUN curl -fsSL https://github.com/pact-foundation/pact-standalone/releases/download/v2.5.12/pact-2.5.12-linux-arm64.tar.gz \
        -o /tmp/pact.tar.gz \
    && tar -xzf /tmp/pact.tar.gz -C /usr/local \
    && rm /tmp/pact.tar.gz \
    && ln -s /usr/local/pact/bin/pact-broker /usr/local/bin/pact-broker

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# ─────────────────────────────────────────────
# PROD TARGET
# ─────────────────────────────────────────────
FROM app_base AS app_prod

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/app.ini

COPY . /app

RUN composer install --no-dev --no-interaction --optimize-autoloader

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
