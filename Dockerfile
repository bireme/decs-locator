FROM docker.io/bitnami/php-fpm:8.4 AS builder

# Install build packages and git
RUN apt update -y && apt install -y git autoconf build-essential

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash


##########################################################################
FROM docker.io/bitnami/php-fpm:8.4 AS dev

# Copy configuration
COPY ./docker/php/php-fpm.conf-development /opt/bitnami/php/etc/php-fpm.conf
COPY ./docker/php/php.ini-development /opt/bitnami/php/etc/php.ini

ENV PHP_INI_SCAN_DIR /opt/bitnami/php/lib/inc

# Copy Symfony CLI from builder stage
COPY --from=builder /root/.symfony5/bin/symfony /usr/local/bin/symfony

WORKDIR /app

EXPOSE 8000

CMD ["symfony", "server:start", "--allow-all-ip"]


##########################################################################
FROM docker.io/bitnami/php-fpm:8.4 AS prod

# Copy configuration
COPY ./docker/php/php-fpm.conf-production /opt/bitnami/php/etc/php-fpm.conf
COPY ./docker/php/php.ini-production /opt/bitnami/php/etc/php.ini

ENV PHP_INI_SCAN_DIR /opt/bitnami/php/lib/inc

# Copy Symfony CLI from builder stage
COPY --from=builder /root/.symfony5/bin/symfony /usr/local/bin/symfony

# Copy composer binary to the image
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Copy dependencies control files
COPY composer.json composer.lock /app

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

# Change cache directory permissions
RUN chmod -R o+w /app/var/cache/

ARG DOCKER_TAG
ENV APP_VER=$DOCKER_TAG

EXPOSE 80