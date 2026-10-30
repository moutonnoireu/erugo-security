# PHP version of the final image (FrankenPHP image tag phpX.Y)
ARG PHP_VERSION=8.5
# FrankenPHP major version (tag of the dunglas/frankenphp image)
ARG FRANKENPHP_VERSION=1

# Build Stage for PHP dependencies (vendor/ is architecture independent)
FROM --platform=$BUILDPLATFORM php:${PHP_VERSION}-cli-alpine AS composer
WORKDIR /app
# Composer from its official image instead of piping an installer script into php
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN apk add --no-cache git unzip
COPY . .
# Production dependencies only: no test/dev tooling in the image
RUN composer install --no-interaction --no-dev --optimize-autoloader --no-scripts

# Build Stage for Node.js dependencies and assets
# Front-end assets are architecture independent: build them natively (no QEMU)
FROM --platform=$BUILDPLATFORM node:22-alpine AS node
WORKDIR /app
COPY . .

RUN npm ci && \
    npx vite build \
    && rm -rf node_modules

# Final production image: FrankenPHP (Caddy with PHP built in) replaces Caddy + php-fpm
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine

LABEL maintainer="Dean Ward"

# Multi-arch support - TARGETARCH is automatically set by Docker buildx
ARG TARGETARCH

# Version passed from build system (e.g., Komodo)
ARG VERSION=unknown
ENV APP_VERSION=${VERSION}

WORKDIR /var/www/html

ENV TZ=UTC
# Port Erugo listens on inside the container. 80 keeps existing port mappings
# working; set 8080 (or any port >= 1024) on runtimes that do not let
# unprivileged processes bind low ports (Kubernetes, old Docker versions).
ENV ERUGO_PORT=80
# Caddy state lives in /tmp so the root filesystem can be mounted read-only
ENV XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

# Runtime packages, and security fixes of the base image packages
RUN apk upgrade --no-cache && \
    apk add --no-cache \
      curl \
      ffmpeg \
      su-exec \
      supervisor \
      tzdata \
      zip

# PHP extensions not compiled into the official PHP image
RUN install-php-extensions \
      bcmath \
      gd \
      intl \
      ldap \
      memcached \
      pcntl \
      pdo_mysql \
      pdo_pgsql \
      redis \
      soap \
      zip && \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# The base image gives frankenphp the cap_net_bind_service file capability.
# Drop it: the server runs unprivileged, and a binary with file capabilities
# cannot even start under `cap_drop: [ALL]` / no-new-privileges.
RUN setcap -r /usr/local/bin/frankenphp 2>/dev/null || true; frankenphp version

# Download and install tusd for the correct architecture.
# tusd publishes no checksum file: the SHA-256 of each archive is pinned here,
# update both when bumping TUSD_VERSION.
ENV TUSD_VERSION=2.10.1
ARG TUSD_SHA256_AMD64=e8c8d7738275d2d9377db953aecbc242262d3620e77fbd3c1491361d9cea6ac8
ARG TUSD_SHA256_ARM64=134e575a6524698b54d989958c680f0a4ec243d9622c1926e30e27c2a86e4752
RUN case "${TARGETARCH}" in \
      amd64) TUSD_SHA256="${TUSD_SHA256_AMD64}" ;; \
      arm64) TUSD_SHA256="${TUSD_SHA256_ARM64}" ;; \
      *) echo "Unsupported architecture: ${TARGETARCH}" && exit 1 ;; \
    esac && \
    curl -fsSL -o /tmp/tusd.tar.gz "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/tusd_linux_${TARGETARCH}.tar.gz" && \
    echo "${TUSD_SHA256}  /tmp/tusd.tar.gz" | sha256sum -c - && \
    tar -xzf /tmp/tusd.tar.gz -C /tmp && \
    mv /tmp/tusd_linux_${TARGETARCH}/tusd /usr/local/bin/tusd && \
    chmod +x /usr/local/bin/tusd && \
    rm -rf /tmp/tusd.tar.gz /tmp/tusd_linux_${TARGETARCH}

# Set timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Unprivileged user running every process (fixed ids, same as previous images)
RUN addgroup -g 1000 erugo && \
    adduser -D -u 1000 -G erugo erugo

# Application code is owned by root and read-only for the erugo user:
# a compromised PHP process cannot modify the code it runs.
COPY . /var/www/html/
COPY --from=composer /app/vendor /var/www/html/vendor
COPY --from=node /app/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/.env /var/www/html/.git /var/www/html/node_modules /var/www/html/storage /var/www/html/public/hot && \
    # public links into the storage volume (were created at every start)
    ln -s ../storage/app/public /var/www/html/public/storage && \
    ln -s ../storage/app/backgrounds /var/www/html/public/backgrounds && \
    ln -s ../storage/app/public/images /var/www/html/public/images && \
    # package manifest built now, so bootstrap/cache does not need to be writable
    cp -r /var/www/html/storage_skell /var/www/html/storage && \
    php artisan package:discover --ansi && \
    rm -rf /var/www/html/storage && \
    chown -R erugo:erugo /var/www/html/bootstrap/cache

# Configuration files
COPY ./docker/alpine/Caddyfile /etc/frankenphp/Caddyfile
COPY ./docker/alpine/start-container /usr/local/bin/start-container
COPY ./docker/alpine/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/alpine/php.ini $PHP_INI_DIR/conf.d/99-erugo.ini
RUN chmod 755 /usr/local/bin/start-container

EXPOSE 80/tcp

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${ERUGO_PORT}/api/health" > /dev/null || exit 1

ENTRYPOINT ["start-container"]
CMD []
