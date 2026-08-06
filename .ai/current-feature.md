# Current Feature: Replace Bitnami Legacy PHP image with Official PHP Docker Image

## Status

<!-- Not Started|In Progress|Completed -->

In Progress

## Goals

<!-- Goals & requirements -->

- Replace all `docker.io/bitnamilegacy/php-fpm:8.4` base images in the `Dockerfile` (builder, dev, prod stages) with the official `php:8.4-fpm` image
- Port the PHP/PHP-FPM configuration from Bitnami paths (`/opt/bitnami/php/etc/`) to the official image paths (`/usr/local/etc/php/`, `/usr/local/etc/php-fpm.d/`), adapting `docker/php/php.ini-*` and `docker/php/php-fpm.conf-*` as needed
- Install the PHP extensions the app requires (previously bundled by Bitnami) explicitly via `docker-php-ext-install` / `pecl`, verified against `composer.json` platform requirements
- Keep the existing multi-stage layout and behaviour: Symfony CLI in `dev` (`symfony server:start` on port 8000), composer install + asset-map:compile + dump-env in `prod`, `APP_VER` build arg
- Keep the prod FPM unix socket contract working with the nginx container (`phpsock` volume mounted at `/var/run`) and the `static_files` volume
- Both `docker-compose-dev.yml` and `docker-compose.yml` stacks build and run; the app responds correctly on all routes

## Notes

<!-- Any extra notes -->

- Motivation: Bitnami moved its catalog to `bitnamilegacy` (see commit `ef2a130`); those images are unmaintained, so moving to the official Docker Hub PHP images removes the dead-end dependency.
- Files in scope: `Dockerfile`, `docker/php/php.ini-development`, `docker/php/php.ini-production`, `docker/php/php-fpm.conf-development`, `docker/php/php-fpm.conf-production`, possibly `docker/nginx/*` (socket path) and `Makefile`.
- Watch out for: file ownership/permissions (Bitnami runs as non-root `1001`, official runs as root with `www-data`), `PHP_INI_SCAN_DIR`, cache dir writability (`chmod -R o+w /app/var/cache/`), and the FPM listen socket path.
- Runs on PHP 8.4 with Symfony 7.4 LTS (see previous feature).

## Detailed Plan

<!-- Link to detailed plan file -->

[002-official-php-docker-image.md](.ai/plans/002-official-php-docker-image.md)

## History

<!-- Keep this updated. Earliest to latest -->

- 2026-06-10: Starting Upgrade Symfony 7.2 → 7.4 LTS — following plan [001-upgrade-symfony-7-4.md](.ai/plans/001-upgrade-symfony-7-4.md)
- 2026-06-10: Completed Upgrade Symfony 7.2 → 7.4 LTS — bumped all symfony/* from 7.2.* to 7.4.* (installed 7.4.13); verified boot, cache clear, and both routes on PHP 8.4. See [log](.ai/logs/2026-06-10-upgrade-symfony-7-4.md).
- 2026-08-06: Starting Replace Bitnami Legacy PHP image with Official PHP Docker Image — following plan [002-official-php-docker-image.md](.ai/plans/002-official-php-docker-image.md)
