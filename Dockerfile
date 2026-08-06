FROM docker.io/library/php:8.4-fpm AS builder

# Install build packages and git
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash


##########################################################################
FROM docker.io/library/php:8.4-fpm AS base

# Install the extensions not bundled with the official image
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip \
    && rm -rf /var/lib/apt/lists/*

# The project ships a self-contained php-fpm.conf with no include directive,
# so the image default pool drop-ins would never be read anyway
RUN rm -f /usr/local/etc/php-fpm.d/*.conf


##########################################################################
FROM base AS dev

# Copy configuration
COPY ./docker/php/php-fpm.conf-development /usr/local/etc/php-fpm.conf
COPY ./docker/php/php.ini-development /usr/local/etc/php/php.ini

# Copy Symfony CLI from builder stage
COPY --from=builder /root/.symfony5/bin/symfony /usr/local/bin/symfony

# Copy composer binary to the image
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8000

CMD ["symfony", "server:start", "--allow-all-ip"]


##########################################################################
FROM base AS prod

# Copy configuration
COPY ./docker/php/php-fpm.conf-production /usr/local/etc/php-fpm.conf
COPY ./docker/php/php.ini-production /usr/local/etc/php/php.ini

# Copy composer binary to the image
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Copy dependencies control files
COPY composer.json composer.lock /app/

# Change to app directory
WORKDIR /app

# Install project dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy project files
COPY . /app

# Compile project assets
RUN php bin/console asset-map:compile

# Generate environment prod
RUN composer dump-env prod

# Give the FPM workers write access to var/
RUN chown -R www-data:www-data /app/var

ARG DOCKER_TAG
ENV APP_VER=$DOCKER_TAG

EXPOSE 80
