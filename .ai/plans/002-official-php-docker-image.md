# 002 — Replace Bitnami Legacy PHP image with Official PHP Docker Image

## Context

`Dockerfile` builds all three stages (`builder`, `dev`, `prod`) from
`docker.io/bitnamilegacy/php-fpm:8.4`. Bitnami moved its catalog to the
`bitnamilegacy` namespace (commit `ef2a130`), which is unmaintained and will stop
receiving security updates. Move to the official Docker Hub `php:8.4-fpm` image.

Runtime is PHP 8.4 / Symfony 7.4 LTS. `composer.json` requires `php >=8.2`,
`ext-ctype`, `ext-iconv`.

### Findings from analysis of the current image

These shape the plan and are worth knowing before touching anything:

1. **`PHP_INI_SCAN_DIR` currently points at a directory that does not exist.**
   The Dockerfile sets `ENV PHP_INI_SCAN_DIR /opt/bitnami/php/lib/inc`; that path
   is absent from the image, so no `conf.d` overlay has ever been scanned. On the
   official image this variable **must be dropped** — `docker-php-ext-enable`
   registers extensions by writing into `/usr/local/etc/php/conf.d`, so keeping
   the override would silently disable every extension we install.

2. **OPcache is not active today.** `php -m` on `bireme/decs-locator:latest`
   reports an empty `[Zend Modules]` section, despite `php.ini-production`
   setting `opcache.enable=1` — the `zend_extension` line lives in a `conf.d`
   drop-in that is never scanned (see 1). Decision taken: **do not enable
   OPcache** in this feature; it stays off, preserving current behaviour. The
   `[opcache]` block in `php.ini-production` remains inert and harmless. Enabling
   it is a good follow-up, but it is a behaviour change and belongs in its own
   change.

3. **The project's `php.ini` *is* in effect.** `/opt/bitnami/php/lib/php.ini` is a
   symlink to `/opt/bitnami/php/etc/php.ini`, the path the Dockerfile writes to.
   So `php.ini-development` / `php.ini-production` genuinely apply and must be
   carried over.

4. **Composer is present in the dev image** at `/opt/bitnami/php/bin/composer`
   — supplied by Bitnami, not by our Dockerfile. The `dev_install_packages`,
   `dev_update_packages` and `dev_update_symfony` Makefile targets depend on it.
   The official image has no Composer, so the **dev stage must copy it in
   explicitly** or those targets break. (`git` is *not* in the dev image today;
   no need to add it.)

5. **`docker/php/custom.ini` and `docker/php/environment.conf` are dead files** —
   referenced by neither the Dockerfile nor either compose file. Decision taken:
   delete them.

### Decisions taken

| Question       | Decision                                                   |
| -------------- | ---------------------------------------------------------- |
| Base image     | `php:8.4-fpm` (Debian bookworm), not Alpine                |
| Extra modules  | `intl` and `zip` only — **no** OPcache, no Bitnami parity  |
| FPM worker user| `www-data` (replaces Bitnami's `daemon`)                   |
| Dead config    | Delete `custom.ini` and `environment.conf`                 |

`intl` matters because `symfony/intl` is a direct dependency currently backed by
`symfony/polyfill-intl-*`, which only handles the `en` locale correctly. `zip`
lets Composer extract dist archives natively — Bitnami's PHP shipped it, which is
why `composer install` works in the current build; without it the prod build
would need the `unzip` binary installed instead.

## Files in scope

| File                                  | Change                                          |
| ------------------------------------- | ----------------------------------------------- |
| `Dockerfile`                          | Rewrite — new base, new config paths, extensions |
| `docker/php/php-fpm.conf-development` | `user`/`group` → `www-data`                     |
| `docker/php/php-fpm.conf-production`  | `user`/`group` → `www-data`                     |
| `docker/php/php.ini-development`      | Unchanged (new destination path only)           |
| `docker/php/php.ini-production`       | Unchanged (new destination path only)           |
| `docker/php/custom.ini`               | Delete                                          |
| `docker/php/environment.conf`         | Delete                                          |
| `Makefile`                            | `clear_app_cache`: `chmod o+w` → `chown www-data` |
| `docker/nginx/*`                      | No change — socket path stays `/var/run/php-fpm.sock` |
| `docker-compose*.yml`                 | No change                                       |

## Path mapping

| Bitnami                                | Official                              |
| -------------------------------------- | ------------------------------------- |
| `/opt/bitnami/php/etc/php.ini`         | `/usr/local/etc/php/php.ini`          |
| `/opt/bitnami/php/etc/php-fpm.conf`    | `/usr/local/etc/php-fpm.conf`         |
| `/opt/bitnami/php/etc/conf.d/`         | `/usr/local/etc/php/conf.d/`          |
| `/opt/bitnami/php/etc/php-fpm.d/`      | `/usr/local/etc/php-fpm.d/`           |
| `PHP_INI_SCAN_DIR=/opt/bitnami/php/lib/inc` | *(remove — default is correct)*  |

## Implementation steps

### 1. Rewrite the `Dockerfile`

Introduce a shared `base` stage so the extension build happens once and both
`dev` and `prod` inherit it.

```dockerfile
FROM docker.io/library/php:8.4-fpm AS builder

# Install build packages and git
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

# Install Symfony CLI
RUN curl -sS https://get.symfony.com/cli/installer | bash


##########################################################################
FROM docker.io/library/php:8.4-fpm AS base

# Extensions not bundled with the official image
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl zip \
    && rm -rf /var/lib/apt/lists/*

# The project ships a self-contained php-fpm.conf with no include directive,
# so the image's default pool drop-ins would never be read anyway
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
```

Points to preserve deliberately:

- **No `ENV PHP_INI_SCAN_DIR`.** See finding 1.
- **No `CMD` in `prod`** — it inherits `["php-fpm"]` and `docker-php-entrypoint`
  from the base image, matching current behaviour.
- **`php.ini` is copied before `composer install`**, exactly as today.
  `php.ini-production` disables `proc_open` via `disable_functions`; the current
  build tolerates this because of `--no-scripts`. Do not reorder these steps.
- **The Symfony CLI is dropped from the `prod` stage.** It is copied there today
  but never used — prod runs `php-fpm`, and every prod Makefile target calls
  `php bin/console` or `composer`. Removing it is a cleanup, not a behaviour
  change. Revert this one line if it proves contentious.
- `COPY composer.json composer.lock /app/` gains a trailing slash (multi-source
  `COPY` wants an explicit directory destination).

Optional, if image size matters: replace the extension `RUN` with the
docker-library `apt-mark showmanual` / `ldd` pattern to purge `-dev` packages
while retaining the runtime `libicu` / `libzip` shared objects (~35 MB saved).
Keeping the `-dev` packages is more robust and is what this plan assumes.

### 2. Update the FPM pool user

In both `docker/php/php-fpm.conf-development` and `-production`:

```diff
 [www]
-user = daemon
-group = daemon
+user = www-data
+group = www-data
```

Everything else stays: `listen = /var/run/php-fpm.sock`, `listen.mode = 0666`,
`daemonize = no`, `clear_env = no`, the `pm.*` tuning and
`request_terminate_timeout`. The `0666` socket mode is what lets the separate
nginx container connect, so it must not be tightened here.

### 3. Delete dead config

```
git rm docker/php/custom.ini docker/php/environment.conf
```

### 4. Update the Makefile cache target

```diff
 clear_app_cache:
 	@docker compose exec decs_locator php bin/console cache:clear
-	@docker compose exec decs_locator /bin/sh -c 'chmod -R o+w /app/var/cache/'
+	@docker compose exec decs_locator /bin/sh -c 'chown -R www-data:www-data /app/var/cache/'
```

`cache:clear` runs as root inside the container and leaves root-owned files that
the `www-data` workers cannot write; the ownership fix is the counterpart to the
`chown` added in the prod stage.

## Verification

### Dev stack

1. `make dev_build_no_cache && make dev_start`
2. `make dev_sh` →
   - `php -m` lists `intl` and `zip`; `[Zend Modules]` still empty (OPcache off, as decided)
   - `php -i | grep 'Loaded Configuration File'` → `/usr/local/etc/php/php.ini`
   - `php -i | grep -E 'memory_limit|display_errors'` → `256M`, `On`
   - `composer --version` works (finding 4)
3. `curl -sI http://localhost:8030/` → `200`
4. Exercise both routes (`DeCSLocatorController`, `DeCSLocatorAutoComplete`)
5. `make dev_clear_app_cache` runs clean

### Prod stack

1. `make build` (tags `bireme/decs-locator:$(APP_VER)` + `latest`)
2. `make start`
3. `make sh` →
   - `php -i | grep 'Loaded Configuration File'` → `/usr/local/etc/php/php.ini`
   - `memory_limit` → `512M`, `display_errors` → `Off`
   - `ls -l /var/run/php-fpm.sock` → socket exists, mode `0666`
   - `ps aux` → master as `root`, workers as `www-data`
4. `make sh_webserver` → `nc -Uz /var/run/php-fpm.sock` (or simply confirm no
   `connect() to unix:/var/run/php-fpm.sock failed` in nginx logs)
5. `curl -sI -H 'Host: decs-locator.local' http://<container-ip>/` → `200`,
   both routes render, static assets served from the `static_files` volume
6. `make logs` → no PHP warnings about missing extensions or unreadable config
7. Record the new image size against the current 535 MB

## Risks and watch-points

- **`/var/run` is a symlink to `/run` on Debian.** Both compose files mount the
  `phpsock` volume at `/var/run` in the PHP and nginx containers. Docker resolves
  the symlink, so the mount lands on `/run` in both — the same arrangement that
  works today with the (also Debian-based) Bitnami image. Low risk, but the
  socket check in step 3 of prod verification is the one that catches it if the
  resolution differs.
- **`static_files` volume staleness.** If the prod volume already exists, it
  keeps the previous `/app/public` contents rather than taking the new image's.
  Run `make update_static` after deploy, or recreate the volume — pre-existing
  behaviour, not introduced here.
- **Extension drift.** Bitnami shipped ~45 modules; we ship the official default
  set plus `intl` and `zip`. Nothing in `src/`, `config/` or `templates/` uses
  `gd`, `soap`, `ldap`, `bcmath`, `tidy`, `xsl` or any PDO driver, and the app
  has no database (Doctrine was removed — see `DEV.txt`). If a runtime error
  names a missing extension, add it to the `docker-php-ext-install` line in the
  `base` stage.
- **First build is slower.** Compiling `intl` against ICU takes a few minutes; it
  is cached thereafter.
- **`disable_functions` and the Symfony CLI.** `php.ini-development` does *not*
  set `disable_functions` (only the production ini does), so `symfony
  server:start` keeps its access to `proc_open`. Do not copy the production ini
  into the dev stage.

## Amendment (2026-08-06, during implementation)

**OPcache is now ON in prod, reversing the earlier decision.** The official
`php:8.4-fpm` image ships `conf.d/docker-php-ext-opcache.ini`
(`zend_extension=opcache`) enabled out of the box — OPcache does not need to be
installed, only configured. With `PHP_INI_SCAN_DIR` no longer suppressing the
`conf.d` scan, `php.ini-production`'s pre-existing `opcache.enable=1` finally
takes effect. Confirmed with the user: let it turn on. `php.ini-development` sets
`opcache.enable=0`, so dev is unaffected. No file change was needed either way.

Also noted: the base image is Debian **trixie**, not bookworm.

## Out of scope

- ~~Enabling OPcache (see finding 2)~~ — superseded, see Amendment above.
- Slimming the image: the extension layer keeps `libicu-dev`/`libzip-dev`
  (92.8 MB). Prod image is 729 MB vs 535 MB before. See the optional `apt-mark`
  pattern in step 1.
- Bumping the PHP minor version; staying on 8.4.
- Changing the nginx image or configuration.
- Converting the dev stage to nginx + FPM; it keeps the Symfony CLI dev server.
