FROM php:8.4-apache AS base

RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libonig5 \
    && docker-php-ext-install -j"$(nproc)" mbstring \
    && docker-php-ext-enable opcache \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && a2dismod -f status autoindex negotiation \
    && mkdir -p /var/www/site \
    && chown -R www-data:www-data /var/www/site

COPY tuning.conf /etc/apache2/conf-available/tuning.conf
RUN a2enconf tuning

WORKDIR /var/www/site

FROM base AS dev

RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
