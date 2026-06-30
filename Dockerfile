# syntax=docker/dockerfile:1

############################################################
# Stage 1 — Composer dependencies (production, optimized)
############################################################
FROM composer:2 AS vendor

WORKDIR /app

# Install dependencies first for better layer caching. Scripts and autoloader
# generation are deferred until the full source tree is available.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# Bring in the rest of the application and build an authoritative classmap.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


############################################################
# Stage 2 — Runtime (FrankenPHP, Caddy-based PHP server)
############################################################
FROM dunglas/frankenphp:1-php8.3 AS runtime

# Native PHP extensions required by the app:
#   pdo_pgsql -> PostgreSQL via Doctrine
#   intl      -> internationalization (API Platform / Symfony)
#   opcache   -> bytecode caching for performance
#   zip       -> archive handling
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        opcache \
        zip

# Production PHP configuration.
COPY docker/php/app.ini ${PHP_INI_DIR}/conf.d/zz-app.ini

WORKDIR /app

# Application source + vendored dependencies from the build stage.
COPY --from=vendor /app /app

# FrankenPHP / Caddy server configuration.
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# Entrypoint: normalizes DATABASE_URL, generates JWT keys, runs migrations,
# warms nothing extra (cache is pre-warmed below), then starts the server.
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Default build/runtime environment. Render overrides these at deploy time.
ENV APP_ENV=prod \
    APP_DEBUG=0

# Prepare writable runtime dirs, install bundle web assets (Swagger UI / API
# Platform), and pre-warm the prod container cache. APP_SECRET is only needed to
# boot the console here; the real value is injected by Render at runtime (env
# vars stay dynamic in the compiled container, so this placeholder is not baked
# into resolved config).
RUN set -eux; \
    mkdir -p var/cache var/log config/jwt; \
    APP_SECRET=build php bin/console assets:install public --no-interaction; \
    APP_SECRET=build php bin/console cache:clear --no-interaction; \
    chmod -R 0777 var

# Render injects PORT (defaults to 10000); the Caddyfile binds to it.
EXPOSE 10000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
