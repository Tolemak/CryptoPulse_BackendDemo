FROM php:8.4-apache

# mbstring.so links against libonig5 at runtime; listing it explicitly
# alongside libonig-dev keeps apt from treating it as an orphaned
# auto-installed dependency when libonig-dev is purged below.
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libonig5 \
    && docker-php-ext-install -j"$(nproc)" mbstring \
    && docker-php-ext-enable opcache \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && mkdir -p /var/www/site \
    && chown -R www-data:www-data /var/www/site

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/site
