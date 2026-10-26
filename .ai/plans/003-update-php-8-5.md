# 003 — Update PHP version to 8.5.x

## Context

The app runs on the official `php:8.4-fpm` image (plan 002) with Symfony 7.4 LTS. Moving to
PHP 8.5 keeps the runtime on the newest supported minor version. The goal is a version bump
that changes nothing else: same extensions, same config, same behaviour, now on 8.5.x.

### Findings from analysis

1. **Nothing blocks 8.5.** No package in `composer.lock` has an upper PHP bound. Every
   constraint is either `>=X` or a `^7 || ^8` range, and `symfony/polyfill-php85` is already
   locked. Symfony 7.4 supports 8.5.
2. **`composer.json`'s `php: >=8.2` is already wrong.** `doctrine/instantiator` 2.1.0 in
   the lock requires `^8.4`, so the declared floor is lower than what actually installs.
3. **`src/` has no 8.5 deprecation hits.** A grep for non-canonical casts
   (`(integer)`/`(boolean)`/`(double)`/`(binary)`), backticks, `__sleep`/`__wakeup`,
   `setAccessible`, and `*_close`/`*_free` calls found nothing in the 7 files under `src/`.
4. **OPcache is always compiled into PHP 8.5.** It can no longer be built as a shared
   extension. Neither `php.ini-*` file loads it (`;zend_extension=opcache` is commented out),
   so the ini files are fine. The thing to check is whether the 8.5 image still ships
   `conf.d/docker-php-ext-opcache.ini`. If it does, loading the extension again could log a
   startup warning. Expected outcome: OPcache stays on in prod (`opcache.enable=1`) and off
   in dev (`opcache.enable=0`), exactly as on 8.4.
5. **No test suite.** `tests/` contains only `bootstrap.php`. Verification is done by hand,
   on the routes, as in plans 001 and 002.

### Decisions taken

| Question         | Decision                                                         |
| ---------------- | ---------------------------------------------------------------- |
| Image tag        | `php:8.5-fpm` (floating minor; `make build_update_php` gets patches) |
| `php` constraint | `>=8.5` in `composer.json`                                       |
| Dependencies     | Keep the lock as is; bump a package only if install fails or it causes 8.5 deprecations |

## Steps

1. **Branch** `feature/update-php-8-5` from `main`. Leave `config/reference.php`, which has
   unrelated uncommitted changes, out of this branch's commits.
2. **`Dockerfile`**: change both `FROM docker.io/library/php:8.4-fpm` lines (`builder` and
   `base`) to `php:8.5-fpm`. No other change is expected.
3. **`composer.json`**: change `"php": ">=8.2"` to `">=8.5"`. Then run
   `composer update --lock` inside the dev container. That refreshes only the lock's
   `content-hash` and `platform`; no package versions change.
4. **Build** with `make build_update_php` (`--pull --no-cache`) for prod, and run the dev
   build with `--pull` (via `docker compose -f docker-compose-dev.yml build --pull`).
5. **Check the runtime** in both containers:
   - `php -v` reports 8.5.x and `php -m` lists `intl`, `zip` and `Zend OPcache`
   - `php --ini` and `php-fpm -t` start without warnings, including no duplicate-OPcache
     warning. If the duplicate warning shows up, delete `conf.d/docker-php-ext-opcache.ini`
     in the `base` stage, with a short comment explaining why.
   - prod: `opcache.enable` = 1 under FPM. dev: 0.
6. **Check dependencies and deprecations**:
   - `composer install` in dev and `composer validate`
   - `php bin/console about` shows Symfony 7.4.x on PHP 8.5.x
   - `php bin/console cache:clear` in dev and prod, with no deprecation notices from `src/`
   - Note any vendor deprecations in the log. Bump a package only if it is causing them.
7. **Docs**: write `.ai/logs/2026-10-26-update-php-8-5.md` in the format of the earlier logs.
   README and other docs contain no references to 8.4, so they need no update.

## Files

- `Dockerfile`: the two `FROM` lines, plus the OPcache ini removal only if step 5 calls for it
- `composer.json` and `composer.lock`: the `php` constraint and the lock hash
- `.ai/logs/2026-10-26-update-php-8-5.md`: new file

## Verification

- `make build_update_php` and the dev compose build both succeed.
- `docker compose -f docker-compose-dev.yml up`: the home route and the autocomplete route
  return correct responses on port 8000, and the Symfony profiler or logs show no new
  deprecations.
- `docker compose -f docker-compose.yml up`: nginx → FPM over the `phpsock` unix socket
  serves both routes, and static assets load from the `static_files` volume.
- `php -v`, `php -m` and `php -i | grep opcache.enable` match the expectations in step 5.

## Out of scope

- Moving to PHP 8.5 language features or doing a refactor
- A general `composer update`
- Changing the Debian base or switching to Alpine

## Amendment (2026-10-26, during implementation)

- **`src/Service/HttpFetchService.php` was changed**, which the plan did not anticipate. The
  analysis grep (finding 3) missed the `$http_response_header` magic variable, which is
  deprecated in 8.5. It showed up during prod `cache:clear`. The fix replaces it with
  `http_get_last_response_headers()`, calling `http_clear_last_response_headers()` before each
  fetch, so the goal "no new PHP 8.5 deprecations from `src/`" holds.
- **The OPcache workaround in step 5 was not needed.** `php:8.5-fpm` no longer ships
  `docker-php-ext-opcache.ini`.
