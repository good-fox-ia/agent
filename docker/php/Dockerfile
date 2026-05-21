# PHP CLI з розширеннями для Symfony + MongoDB, Redis, RabbitMQ (AMQP)
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    librabbitmq-dev \
    libssh-dev \
    pkg-config \
    libssl-dev \
    libzstd-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl opcache zip \
    && pecl install redis mongodb amqp \
    && docker-php-ext-enable redis mongodb amqp \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
