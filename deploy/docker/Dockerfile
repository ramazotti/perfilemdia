FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=America/Sao_Paulo
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libmagickwand-dev \
        pkg-config \
        libcurl4-openssl-dev \
        unzip \
        curl \
        cron \
        mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd intl mysqli pdo_mysql zip exif opcache mbstring \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && a2enmod rewrite headers \
    && curl -fsSL https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-perfilemdia.ini
COPY deploy/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/docker/crontab /etc/cron.d/perfilemdia
RUN chmod 0644 /etc/cron.d/perfilemdia \
    && ln -sf /usr/local/bin/php /usr/bin/php

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY bin bin
COPY cron cron
COPY migrations migrations
COPY public public
COPY resources resources
COPY src src
RUN mkdir -p storage/logs storage/media storage/logos storage/prompt_extras public/m \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data storage public/m

EXPOSE 80
CMD ["apache2-foreground"]
