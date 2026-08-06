# 2026-08-06 — Replace Bitnami Legacy PHP image with Official PHP Docker Image

## Summary

Moved all three build stages from `docker.io/bitnamilegacy/php-fpm:8.4` to the official
`docker.io/library/php:8.4-fpm` (Debian trixie). Bitnami relocated its catalog to the
`bitnamilegacy` namespace (commit `ef2a130`), which no longer receives updates.

Behaviour is preserved: Symfony CLI dev server on :8000, prod `composer install` →
`asset-map:compile` → `dump-env`, FPM over the unix socket shared with the nginx container.
One deliberate behaviour change: **OPcache is now active in prod** (see Decisions).

## Changes

- **`Dockerfile`** — new base image; added a shared `base` stage so the extension build happens
  once and `dev`/`prod` inherit it. Installs `intl` and `zip` via `docker-php-ext-install`
  (the official image ships neither). Config destinations moved to `/usr/local/etc/php/php.ini`
  and `/usr/local/etc/php-fpm.conf`. Removed `ENV PHP_INI_SCAN_DIR`. Added Composer to the `dev`
  stage, removed the unused Symfony CLI copy from `prod`, and replaced
  `chmod -R o+w /app/var/cache/` with `chown -R www-data:www-data /app/var`.
- **`docker/php/php-fpm.conf-development` / `-production`** — pool `user`/`group`
  `daemon` → `www-data`. Socket path and `listen.mode = 0666` unchanged, so the nginx container
  still reaches it.
- **`docker/php/custom.ini`, `docker/php/environment.conf`** — deleted; referenced by neither the
  Dockerfile nor either compose file.
- **`Makefile`** — `clear_app_cache` now runs `chown -R www-data:www-data /app/var/cache/`
  instead of `chmod -R o+w`, matching the new worker user.
- **`src/Controller/DeCSLocatorAutoComplete.php`** — replaced three deprecated `Request::get()`
  calls with `$request->query->get()` (symfony/http-foundation 7.4 deprecation). Captured the raw
  input in `$search_term` (default `''`) before `$query` is mutated, which removes both the
  `str_replace(): Passing null` deprecation and the `Undefined array key "query"` warning from
  `$_REQUEST['query']`.
- **`src/Controller/DeCSLocatorController.php`, `src/Service/CacheService.php`,
  `src/Service/HttpFetchService.php` (new)** — an upstream-fetch resilience refactor that arrived
  during this feature. Unrelated to the image swap and **incomplete** — see Follow-ups.

## Findings from the old image

1. `PHP_INI_SCAN_DIR` pointed at `/opt/bitnami/php/lib/inc`, which does not exist in the image, so
   no `conf.d` overlay was ever scanned. Carrying it over would have silently disabled every
   extension installed on the official image, since `docker-php-ext-install` registers extensions
   by writing there.
2. Consequently OPcache was never loaded (`php -m` showed an empty `[Zend Modules]`) despite
   `php.ini-production` setting `opcache.enable=1`.
3. Composer existed in the dev image only because Bitnami bundles it; `dev_install_packages`,
   `dev_update_packages` and `dev_update_symfony` depend on it. The official image has none.
4. `ext-zip` was likewise supplied by Bitnami, and Composer needs it (or the `unzip` binary) to
   extract dist archives during the prod build.

## Verification (both stacks built and run)

- **Dev** (`docker-compose-dev.yml`) — `GET /locate/` → 200; `GET /autocomplete/en/?query=dengue`
  → 200 with live DeCS data; JSONP `&callback=cb` wrapper intact; `composer --version` and
  `composer install --dry-run` OK; `cache:clear` OK. `intl`/`zip` loaded, php.ini at
  `/usr/local/etc/php/php.ini`, `memory_limit 256M`, `display_errors On`, OPcache off.
- **Prod** (`docker-compose.yml` + nginx) — both routes 200 through the FastCGI socket, static
  assets 200, no nginx errors. `/var/run/php-fpm.sock` present at mode `0666` and visible from the
  nginx container, so the `/var/run` → `/run` symlink is a non-issue. FPM master root, workers
  uid 33 (`www-data`). `memory_limit 512M`, `display_errors Off`, `bin/console about` reports
  OPcache Enabled.
- Builds needed `docker build --network=host` in this environment because containers have no
  egress on the default bridge. No repo file was changed to accommodate that; `make dev_build` /
  `make build` are unaffected on a normal network.

## Decisions

- Base image `php:8.4-fpm` (Debian), not Alpine — glibc keeps the Symfony CLI binary and any
  future pecl builds straightforward.
- Install only `intl` and `zip`, not Bitnami's full ~45-extension surface. Nothing in `src/`,
  `config/` or `templates/` uses `gd`, `soap`, `ldap`, `bcmath`, `tidy`, `xsl` or any PDO driver,
  and the app has no database.
- **OPcache left enabled in prod.** The official image ships
  `conf.d/docker-php-ext-opcache.ini` pre-enabled, so with `PHP_INI_SCAN_DIR` gone the committed
  `opcache.enable=1` finally takes effect. `php.ini-development` sets `opcache.enable=0`, so dev
  is unaffected.
- FPM workers as `www-data` rather than keeping Bitnami's `daemon`.

## Follow-ups (not done)

- **`CacheService.php:69` 500s in dev.** Dropping the `@` from `file_get_contents` lets Symfony's
  error handler convert the warning to an `ErrorException` before the new `if ($api_response ===
  false)` guard can run, so the guard is unreachable in dev. Prod is unaffected (warnings are
  logged, not thrown). Restoring the `@` fixes it.
- **`templates/regional/decs-locator-page.html:130` throws on a null `decs`** —
  `Twig\Error\RuntimeError: Impossible to access an attribute ("tree") on a null variable`. The
  controller was made null-tolerant but the template was not, so the intended graceful degradation
  never reaches the view. This one throws in prod too.
- **`HttpFetchService` is mostly unused** — `loadXmlFile()`, `getResponseHeaders()` and
  `getResponseStatusCode()` have no callers.
- **`HTTP_FETCH_TIMEOUT` cannot be set via the container environment.** It is read from `$_ENV`,
  but both `php.ini-*` set `variables_order = "GPCS"` (no `E`), so `$_ENV` is not populated from
  real environment variables — only `.env` / `dump-env` values land there.
- **Prod image grew 535 MB → 729 MB.** ~93 MB is the extension layer retaining
  `libicu-dev`/`libzip-dev`; the docker-library `apt-mark`/`ldd` purge pattern would recover
  ~70 MB. The remainder is the official base being larger than Bitnami's.
- **No `.dockerignore`**, so `COPY . /app` bakes the host's `var/cache`, `vendor/` and `.git` into
  the prod image. This is pre-existing, but it means a stale cache can be shipped in the image and
  it inflates the build context.
- **`/locate/` could not be exercised end-to-end from this machine**: `api.bvsalud.org` returns
  `403 Forbidden` (nginx-level) for `DECS_APIKEY_LOCATE`, while `srv.bvsalud.org` (autocomplete)
  works. Reproduced identically on the pre-change code, so it is environmental — an IP restriction
  or an expired key. Confirm `/locate/` from an allowed host before deploying.

## Plan

[002-official-php-docker-image.md](../plans/002-official-php-docker-image.md)
