# FrankenPHP - PHP 8.5 Alpine (Symfony 8.0 requires PHP >= 8.4)
FROM dunglas/frankenphp:1-php8.5-alpine

RUN apk add --no-cache git unzip bash
RUN install-php-extensions intl pcov zip pdo_pgsql pdo_sqlite
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN git config --global --add safe.directory /app && \
    git config --global --add safe.directory /var/api-studio-bundle

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

RUN echo '#!/bin/sh' > /usr/local/bin/docker-entrypoint.sh && \
    echo 'set -e' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'git config --global --add safe.directory /app 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'git config --global --add safe.directory /var/api-studio-bundle 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'mkdir -p /app/var/cache /app/var/log' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'chmod -R 777 /app/var 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'if [ "${APP_ENV:-prod}" = "dev" ]; then cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile; fi' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'if [ ! -f /app/vendor/autoload_runtime.php ]; then' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '  i=0' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '  until composer install --no-interaction --prefer-dist; do' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '    i=$((i+1))' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '    if [ "$i" -ge 5 ]; then echo "composer install failed after 5 attempts" >&2; exit 1; fi' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '    echo "composer install failed, retry $$i in 8s..." >&2' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '    sleep 8' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '  done' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'fi' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'if [ -f /app/bin/console ]; then' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '  php /app/bin/console nowo:api-studio:sync-schema --no-interaction 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo '  php /app/bin/console nowo:api-studio:seed-demo --no-interaction 2>/dev/null || true' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'fi' >> /usr/local/bin/docker-entrypoint.sh && \
    echo 'exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile' >> /usr/local/bin/docker-entrypoint.sh && \
    chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
