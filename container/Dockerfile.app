ARG BASE=cryptopulse-base

FROM ${BASE} AS vendor
WORKDIR /src
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader

FROM ${BASE}
ARG APP_UID=10001
ARG APP_GID=10001
USER root
WORKDIR /var/www/site
COPY . /var/www/site
COPY --from=vendor /src/vendor /var/www/site/vendor
ENV APACHE_RUN_USER="#${APP_UID}" \
    APACHE_RUN_GROUP="#${APP_GID}" \
    APACHE_RUN_DIR=/run/apache2 \
    APACHE_PID_FILE=/run/apache2/apache2.pid \
    APACHE_LOCK_DIR=/run/lock/apache2
RUN set -eu; \
    for p in .git .env .env.local .env.local.php var; do \
      if [ -e "$p" ] || [ -L "$p" ]; then echo "forbidden path in image: $p" >&2; exit 1; fi; \
    done; \
    test -f vendor/autoload.php; \
    install -m 0644 -o 0 -g 0 container/apache/default.conf /etc/apache2/sites-available/000-default.conf; \
    install -m 0644 -o 0 -g 0 container/php/php.ini /usr/local/etc/php/php.ini; \
    chown -R 0:0 /var/www/site; \
    chmod -R u=rwX,go=rX /var/www/site; \
    mkdir -m 0750 var; \
    chown "${APP_UID}:${APP_GID}" var; \
    conf=/etc/apache2; \
    printf '%s\n' '' \
      "export APACHE_RUN_USER='#${APP_UID}'" \
      "export APACHE_RUN_GROUP='#${APP_GID}'" \
      'export APACHE_RUN_DIR=/run/apache2' \
      'export APACHE_PID_FILE=/run/apache2/apache2.pid' \
      'export APACHE_LOCK_DIR=/run/lock/apache2' >> "$conf/envvars"; \
    grep -qx 'Listen 80' "$conf/ports.conf"; \
    sed -i 's/^Listen 80$/Listen 8080/' "$conf/ports.conf"; \
    sed -i --follow-symlinks -E 's/<VirtualHost ([^>:]*):80>/<VirtualHost \1:8080>/' "$conf"/sites-enabled/*.conf; \
    if grep -REq '<VirtualHost [^>]*:80>' "$conf/sites-enabled/"; then echo 'vhost still on :80' >&2; exit 1; fi; \
    mkdir -p /run/apache2 /run/lock/apache2; \
    chown "${APP_UID}:${APP_GID}" /run/apache2 /run/lock/apache2; \
    chmod 0700 /run/apache2 /run/lock/apache2; \
    sed -i --follow-symlinks -E \
      -e 's#^([[:space:]]*ErrorLog[[:space:]]+).*$#\1/proc/self/fd/2#' \
      -e 's#^([[:space:]]*CustomLog[[:space:]]+)[^[:space:]]+#\1/proc/self/fd/1#' \
      "$conf/apache2.conf" "$conf"/sites-available/*.conf "$conf"/conf-available/*.conf; \
    if grep -REq 'APACHE_LOG_DIR|/var/log' "$conf/apache2.conf" "$conf"/sites-enabled/ "$conf"/conf-enabled/; then echo 'log path left in apache config' >&2; exit 1; fi; \
    grep -Eq '^[[:space:]]*ErrorLog[[:space:]]+/proc/self/fd/2$' "$conf/apache2.conf"; \
    ( set +u; . "$conf/envvars"; apache2 -t )
USER ${APP_UID}:${APP_GID}
EXPOSE 8080
