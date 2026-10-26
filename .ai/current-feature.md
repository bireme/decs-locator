# Current Feature: Update PHP version to 8.5.x

## Status

<!-- Not Started|In Progress|Completed -->

Completed

## Goals

<!-- Goals & requirements -->

- Bump the `builder` and `base` stages in the `Dockerfile` from `php:8.4-fpm` to `php:8.5-fpm` (tracking the latest 8.5.x patch)
- `intl` and `zip` extensions still build and load via `docker-php-ext-install`; OPcache stays active in prod
- `docker/php/php.ini-*` and `docker/php/php-fpm.conf-*` remain valid on 8.5 (no removed/renamed directives, no startup warnings)
- All Composer dependencies install on 8.5 (Symfony 7.4 LTS and the rest of `composer.lock`), updating packages only where needed for compatibility
- No new PHP 8.5 deprecations from app code (`src/`); vendor deprecations noted if any
- Both `docker-compose-dev.yml` and `docker-compose.yml` stacks build and run, and the app responds correctly on all routes

## Notes

<!-- Any extra notes -->

- Current state: official `php:8.4-fpm` image (see previous feature); `composer.json` requires `php >=8.2`, so the constraint doesn't block 8.5 — decide whether to raise it.
- Files in scope: `Dockerfile`, possibly `docker/php/*`, `composer.json`/`composer.lock`, and docs mentioning PHP 8.4.
- `make build_update_php` (`--pull --no-cache`) already exists for refreshing the base image.
- Watch out for: PHP 8.5 deprecations (e.g. non-canonical cast names, backtick operator, `__sleep`/`__wakeup` soft deprecation), and OPcache being always compiled-in on 8.5 (check `opcache` ini settings still apply).
- Unrelated uncommitted change in `config/reference.php` (Symfony-regenerated config reference) is in the working tree — handle separately or commit alongside.

## Detailed Plan

<!-- Link to detailed plan file -->

[003-update-php-8-5.md](.ai/plans/003-update-php-8-5.md)

## History

<!-- Keep this updated. Earliest to latest -->

- 2026-06-10: Starting Upgrade Symfony 7.2 → 7.4 LTS — following plan [001-upgrade-symfony-7-4.md](.ai/plans/001-upgrade-symfony-7-4.md)
- 2026-06-10: Completed Upgrade Symfony 7.2 → 7.4 LTS — bumped all symfony/* from 7.2.* to 7.4.* (installed 7.4.13); verified boot, cache clear, and both routes on PHP 8.4. See [log](.ai/logs/2026-06-10-upgrade-symfony-7-4.md).
- 2026-08-06: Starting Replace Bitnami Legacy PHP image with Official PHP Docker Image — following plan [002-official-php-docker-image.md](.ai/plans/002-official-php-docker-image.md)
- 2026-08-06: Completed Replace Bitnami Legacy PHP image with Official PHP Docker Image — all stages on `php:8.4-fpm` with `intl`/`zip`, config moved to `/usr/local/etc`, FPM workers as `www-data`; OPcache now active in prod. Also fixed the `Request::get()` deprecations in the autocomplete controller. See [log](.ai/logs/2026-08-06-official-php-docker-image.md).
- 2026-10-26: Starting Update PHP version to 8.5.x — following plan [003-update-php-8-5.md](.ai/plans/003-update-php-8-5.md)
