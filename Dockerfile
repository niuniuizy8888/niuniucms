# NiuNiuCMS production image: nginx + PHP 8.5-FPM
# Build:  docker compose build
# Run:    docker compose up -d
#
# China mirror example:
#   docker build --build-arg PHP_IMAGE=docker.m.daocloud.io/library/php:8.5-fpm -t niuniucms:local .
#
ARG PHP_IMAGE=php:8.5-fpm
FROM ${PHP_IMAGE}

LABEL org.opencontainers.image.title="NiuNiuCMS" \
      org.opencontainers.image.description="PHP 8.5-FPM + nginx for NiuNiuCMS"

ENV DEBIAN_FRONTEND=noninteractive \
    NN_APP_ROOT=/var/www/nncms

# Installer needs: pdo_mysql mbstring gd zip opcache curl fileinfo (+ sodium usually built-in)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx \
        curl \
        ca-certificates \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        mbstring \
        gd \
        zip \
        opcache \
        bcmath; \
    docker-php-ext-enable opcache || true; \
    rm -rf /var/lib/apt/lists/*; \
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf; \
    rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-nncms.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/conf.d/nncms.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && mkdir -p /run/nginx /var/log/nginx \
    && chown -R www-data:www-data /var/log/nginx

WORKDIR ${NN_APP_ROOT}
COPY --chown=www-data:www-data . ${NN_APP_ROOT}

RUN mkdir -p data runtime/cache runtime/log runtime/tmp upload plugins template \
    && chown -R www-data:www-data data runtime upload plugins template \
    && chmod 555 core || true

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
  CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1

ENTRYPOINT ["/entrypoint.sh"]
