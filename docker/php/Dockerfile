# PHP CLI з розширеннями для Symfony + MongoDB, Redis, RabbitMQ (AMQP)
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ffmpeg \
    git \
    python3 \
    python3-pip \
    unzip \
    libicu-dev \
    libzip-dev \
    librabbitmq-dev \
    libssh-dev \
    pkg-config \
    libssl-dev \
    libzstd-dev \
    libzstd1 \
    libbsd-dev \
    libbsd0 \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl opcache zip \
    && pecl install redis amqp \
    && LDFLAGS="-lbsd" LIBS="-lbsd" pecl install mongodb \
    && docker-php-ext-enable redis mongodb amqp \
    && ldd "$(php-config --extension-dir)/mongodb.so" | grep -q libbsd \
    && curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh \
    && pip3 install --break-system-packages -U "yt-dlp[default]" gallery-dl curl_cffi \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
