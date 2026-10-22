# decoupled.io WordPress tenant image: FrankenPHP + MariaDB sidecar in one
# container, the WordPress twin of decoupled-project's Dockerfile.
#
# Deployed to the `decoupled-wp` source Fly app, which
# decoupled-dashboard's provision-wp-tenant.sh clones for each WP tenant:
#   fly deploy -a decoupled-wp --remote-only
#
# Recovered 2026-10-22 from the deployed image's layer history: the build
# files were never committed, so the image could not be rebuilt when
# PHP2Shell (CVE-2026-109618/109619) needed PHP 8.5.12. The floating tag
# below picks up PHP patch releases on every rebuild.
FROM dunglas/frankenphp:1-php8.5

# Composer and WP-CLI (FrankenPHP ships neither).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp

# Base tools + the MariaDB sidecar. gosu drops to the mysql user when
# entrypoint.sh starts mariadbd.
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git unzip ca-certificates curl gosu less \
        mariadb-server mariadb-client && \
    rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    gd \
    intl \
    opcache \
    mysqli \
    pdo_mysql \
    zip \
    exif \
    apcu

COPY docker/apcu.ini /usr/local/etc/php/conf.d/apcu.ini
COPY docker/wp.ini /usr/local/etc/php/conf.d/zz-wp.ini

WORKDIR /app

COPY composer.json composer.lock ./
COPY web/ ./web/
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# Uploads live on the /data volume so they survive machine replacement.
RUN rm -rf /app/web/app/uploads && \
    ln -s /data/uploads /app/web/app/uploads

COPY Caddyfile /etc/frankenphp/Caddyfile
COPY wp-cli.yml /app/wp-cli.yml

RUN mkdir -p /data /run/mysqld && \
    chown mysql:mysql /run/mysqld
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/99-decoupled.cnf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
ENV WP_CLI_ALLOW_ROOT=1
ENV PORT=8080
EXPOSE 8080
