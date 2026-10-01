# RivetMSP — Docker Compose runtime image.
#
# This image is deliberately NOT a self-contained distributable — it ships
# nginx + PHP-FPM 8.4 + supervisord + redis-server only. The application
# code always comes from a bind mount (see ../docker-compose.yml), the same
# git checkout you cloned from GitHub, so config.php/uploads/backups/vendor
# written at runtime live on the host and survive image rebuilds, and
# `git pull` + a container restart is the update path — no separate "bake a
# new image with new code" step to keep in sync with deploy/update.sh's
# git-pull model.
#
# Extension list mirrors deploy/install.sh's REQUIRED_PHP_PACKAGES exactly
# (php8.4-mysql -> mysqli+pdo_mysql, php8.4-curl, php8.4-gd, php8.4-mbstring,
# php8.4-intl, php8.4-xml, php8.4-zip, php8.4-bcmath, php8.4-opcache) so this
# image and a bare-metal install.sh box behave the same way at the PHP
# level. Keep the two lists in sync if either changes.
#
# redis-server is included (run by supervisord on 127.0.0.1:6380, matching
# includes/redis_functions.php's hardcoded REDIS_HOST/REDIS_PORT) so live
# ticket/chat push works the same as it does bare-metal, instead of
# silently degrading in every container deployment. openssl is included for
# deploy/restore.sh's decrypt step (see RESTORE_FROM in entrypoint.sh).

FROM composer:2 AS composer

FROM php:8.4-fpm-bookworm

COPY --from=composer /usr/bin/composer /usr/bin/composer

# Purging the -dev/header packages by NAME below (never --auto-remove) is
# load-bearing: confirmed live that `apt-get purge --auto-remove` on these
# cascades into removing the RUNTIME shared libraries too (libpng16-16,
# libzip4, etc.) — apt sees them as orphaned once the -dev package that
# pulled them in is gone, with no way to know the compiled gd/zip
# extensions still dlopen() them at runtime. That produced a real "Unable
# to load dynamic library 'gd'... libpng16.so.16: cannot open shared object
# file" startup warning the first time this image was actually run, not
# just built. Naming the exact -dev packages to purge (no --auto-remove)
# leaves every runtime lib in place.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor rsync unzip git default-mysql-client openssl redis-server sudo \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli pdo_mysql gd intl zip bcmath opcache mbstring curl \
    && apt-get purge -y \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# upload_max_filesize/post_max_size match client_max_body_size 500M in
# deploy/templates/nginx-vhost.conf.template. opcache.validate_timestamps=1
# (upstream php.ini-production default is 0) for the same reason
# deploy/update.sh reloads php-fpm after a git pull: a `git pull` here is
# not accompanied by any reload, so opcache must notice changed files on
# its own.
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-itflow-overrides.ini
COPY docker/nginx.conf /etc/nginx/sites-available/itflow.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/itflow.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/itflow.conf /etc/nginx/sites-enabled/itflow.conf \
    && chmod +x /entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
