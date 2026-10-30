# PHP version of the final image (Alpine packages phpXY-*)
ARG PHP_VERSION=85

# Build Stage for PHP dependencies (vendor/ is architecture independent)
FROM --platform=$BUILDPLATFORM php:8.5-cli-alpine AS composer
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

# Final production image
FROM alpine:3.24

LABEL maintainer="Dean Ward"

# Multi-arch support - TARGETARCH is automatically set by Docker buildx
ARG TARGETARCH
ARG PHP_VERSION

# Version passed from build system (e.g., Komodo)
ARG VERSION=unknown
ENV APP_VERSION=${VERSION}

WORKDIR /var/www/html

ENV TZ=UTC
ENV SUPERVISOR_PHP_FPM_COMMAND="/usr/sbin/php-fpm -F"
ENV SUPERVISOR_CADDY_COMMAND="/usr/sbin/caddy run --config /etc/caddy/Caddyfile"
ENV SUPERVISOR_PHP_USER="erugo"

# Enable community repository for PHP packages
RUN echo "http://dl-cdn.alpinelinux.org/alpine/v3.24/community" >> /etc/apk/repositories

# Install production requirements
RUN apk add --no-cache \
    curl \
    su-exec \
    sudo \
    supervisor \
    cronie \
    ffmpeg \
    php${PHP_VERSION} \
    php${PHP_VERSION}-pdo_pgsql \
    php${PHP_VERSION}-pdo_sqlite \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-pdo_mysql \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-soap \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-ldap \
    php${PHP_VERSION}-pecl-redis \
    php${PHP_VERSION}-pecl-memcached \
    php${PHP_VERSION}-phar \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-dom \
    php${PHP_VERSION}-tokenizer \
    php${PHP_VERSION}-fileinfo \
    php${PHP_VERSION}-sodium \
    tzdata \
    libcap \
    zip \
    php${PHP_VERSION}-fpm \
    caddy \
    && ln -sf /usr/bin/php${PHP_VERSION} /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm${PHP_VERSION} /usr/sbin/php-fpm \
    && ln -sfn /etc/php${PHP_VERSION} /etc/php \
    && rm -rf /var/cache/apk/*

# Download and install tusd for the correct architecture
ENV TUSD_VERSION=2.10.1
RUN TUSD_ARCH="${TARGETARCH}" && \
    if [ "$TUSD_ARCH" = "amd64" ]; then TUSD_ARCH="amd64"; \
    elif [ "$TUSD_ARCH" = "arm64" ]; then TUSD_ARCH="arm64"; \
    else echo "Unsupported architecture: $TARGETARCH" && exit 1; fi && \
    curl -fsSL -o /tmp/tusd.tar.gz "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/tusd_linux_${TUSD_ARCH}.tar.gz" && \
    curl -fsSL -o /tmp/tusd-checksums.txt "https://github.com/tus/tusd/releases/download/v${TUSD_VERSION}/checksums.txt" && \
    (cd /tmp && grep " tusd_linux_${TUSD_ARCH}.tar.gz\$" tusd-checksums.txt | sed 's/ \+/  /' | sed "s|tusd_linux_${TUSD_ARCH}.tar.gz|tusd.tar.gz|" | sha256sum -c -) && \
    tar -xzf /tmp/tusd.tar.gz -C /tmp && \
    mv /tmp/tusd_linux_${TUSD_ARCH}/tusd /usr/local/bin/tusd && \
    chmod +x /usr/local/bin/tusd && \
    rm -rf /tmp/tusd.tar.gz /tmp/tusd-checksums.txt /tmp/tusd_linux_${TUSD_ARCH}

# Create necessary directories
RUN mkdir -p /run/php /var/www/html/public /etc/php/php-fpm.d /etc/caddy

COPY ./docker/alpine/www.conf /etc/php/php-fpm.d/www.conf
COPY ./docker/alpine/Caddyfile /etc/caddy/Caddyfile

# Set timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Configure PHP capabilities
RUN setcap "cap_net_bind_service=+ep" /usr/bin/php${PHP_VERSION}

# Create erugo user and group
RUN addgroup erugo && \
    adduser -D -G erugo erugo

# Set permissions
RUN chown -R erugo:erugo /run/php

# Copy necessary files from build stages and local context
COPY --chown=erugo:erugo . /var/www/html/
COPY --from=composer /app/vendor /var/www/html/vendor
COPY --from=node /app/public/build /var/www/html/public/build
RUN rm -rf /var/www/html/.env /var/www/html/.git /var/www/html/node_modules /var/www/html/storage

# Set up Laravel scheduler cron job
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -u erugo -

# Copy configuration files
COPY ./docker/alpine/start-container /usr/local/bin/start-container
COPY ./docker/alpine/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ./docker/alpine/php.ini /etc/php/conf.d/99-erugo.ini
RUN chmod +x /usr/local/bin/start-container

# Create supervisor log directory
RUN mkdir -p /var/log/supervisor

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]