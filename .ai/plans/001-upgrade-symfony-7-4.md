# Plan: Upgrade Symfony 7.2 → 7.4 LTS

## Context

The `decs-locator` app pins every `symfony/*` package to `7.2.*`. The goal is to move to
**Symfony 7.4** — the LTS release of the 7.x branch (latest patch **7.4.13**),
supported with bug fixes to Nov 2028 and security fixes to Nov 2029. Staying on an LTS reduces
upgrade churn and is the right base before any eventual 8.x jump.

This is a same-major upgrade (7.2 → 7.3 → 7.4), so Symfony's BC policy guarantees **no breaking
changes** — only new deprecations. The app's custom code surface is tiny and uses only stable
Symfony APIs, so risk is low.

**Key environment facts (drive the approach):**
- No local PHP/Composer — both run **only inside Docker** (`bitnamilegacy/php-fpm:8.4`). All
  composer/console commands must run via the Make targets / `docker compose exec`.
- The Makefile already has `dev_update_symfony` → `composer update "symfony/*" --with-all-dependencies`.
- No Doctrine ORM (only `symfony/doctrine-messenger`). Custom code: `src/Controller/*`,
  `src/Service/*`, `src/Twig/AppExtension.php`, `src/Kernel.php` — all using stable APIs
  (`AbstractController`, `Request`/`Response`, `#[Route]` attribute, Cache contracts).
- **No real tests** exist (only `tests/bootstrap.php`); verification is boot + manual route checks.

**Decisions confirmed with user:**
- **Leave recipe-managed config as-is** — do NOT run `composer recipes:update`. Only fix config
  that actually breaks.
- **Symfony-only, minimal scope** — bump `symfony/*` to 7.4 plus only the transitive deps needed
  to satisfy them. Keep PHPUnit at `^9.6` and avoid non-Symfony major bumps.

## Scope

In scope:
- `composer.json`: change every `symfony/* : "7.2.*"` → `"7.4.*"` (in `require` and `require-dev`),
  and `extra.symfony.require: "7.2.*"` → `"7.4.*"`.
- Run the Symfony-scoped composer update inside Docker; regenerate `composer.lock` / `symfony.lock`.
- Clear cache, boot the app, smoke-test the two routes, scan for new deprecations.

Out of scope (per user):
- `composer recipes:update` / refreshing scaffolded config files.
- PHPUnit 9 → 11/12 migration, writing new tests, modernizing `phpunit.xml.dist`.
- Non-Symfony major upgrades (twig, monolog-bundle stay on current ranges; may receive minor bumps
  only as transitive requirements).
- Any Symfony 8.0 / PHP-version-requirement changes.

## Implementation Steps

### Step 0 — Feature bookkeeping
- Copy this plan to `.ai/plans/001-upgrade-symfony-7-4.md`.
- In `.ai/current-feature.md`, fill the `## Detailed Plan` section with a link:
  `[001-upgrade-symfony-7-4.md](.ai/plans/001-upgrade-symfony-7-4.md)`.
  (Deferred to post-approval because plan mode only permits editing the plan file.)

### Step 1 — Bump version constraints in `composer.json`
Change `7.2.*` → `7.4.*` for all `symfony/*` entries. Representative entries (pattern applies to
**all** ~28 `symfony/*` requires across `require` and `require-dev`):
- `require`: `symfony/framework-bundle`, `symfony/console`, `symfony/runtime`, `symfony/twig-bundle`,
  `symfony/security-bundle`, `symfony/serializer`, `symfony/validator`, `symfony/form`, `symfony/yaml`,
  `symfony/asset`, `symfony/asset-mapper`, `symfony/http-client`, `symfony/mailer`, `symfony/notifier`,
  `symfony/doctrine-messenger`, `symfony/intl`, `symfony/string`, `symfony/translation`, … (all `7.2.*`).
- `require-dev`: `symfony/browser-kit`, `symfony/css-selector`, `symfony/debug-bundle`,
  `symfony/stopwatch`, `symfony/web-profiler-bundle` (all `7.2.*`).
- `extra.symfony.require`: `"7.2.*"` → `"7.4.*"`.
- **Leave untouched** (already correct / not Symfony-versioned): `symfony/flex` (`^2.11`),
  `symfony/monolog-bundle` (`^3.11.2`), `symfony/phpunit-bridge` (already `^7.4.8`),
  `php` (`>=8.2`), `twig/*`, `phpunit/phpunit` (`^9.6`), `phpdocumentor/*`, `phpstan/*`.

### Step 2 — Run the update inside Docker
- Ensure the dev container is up: `make dev_start` (uses `docker-compose-dev.yml`).
- Run: `make dev_update_symfony`
  (= `docker compose -f docker-compose-dev.yml exec decs_locator composer update "symfony/*" --with-all-dependencies`).
- This rewrites `composer.lock`; Flex updates `symfony.lock`. Watch output for conflicts. Because
  recipes are left as-is, decline any interactive recipe prompts (or rely on non-interactive default).

### Step 3 — Clear cache & verify boot
- `make dev_clear_app_cache` (`php bin/console cache:clear`).
- Confirm the kernel boots: `docker compose -f docker-compose-dev.yml exec decs_locator php bin/console about`
  → should report Symfony `7.4.x`.

### Step 4 — Address deprecations / breakage (only if surfaced)
- Check for deprecations: `php bin/console debug:container --deprecations` and the dev log
  (`var/log/dev.log`). Given the small, stable-API codebase, none are expected, but fix any that
  appear in **our** code (not vendor). Config is left as-is per decision; only touch a
  `config/packages/*.yaml` key if the upgrade hard-fails on it.

### Step 5 — Smoke-test the app
Boot the dev server (`make dev_run` serves via Symfony CLI on :8000) and exercise both routes:
- `GET /locate/?lang=pt` (and `?descriptor=...`, `?tree_id=...`, `?mode=dataentry`) — the main
  DeCS lookup page (`DeCSLocatorController`). Needs `DECS_APIKEY_LOCATE` / `DECS_APIKEY_DATAENTRY`
  env; if unset, verify it renders/handles gracefully rather than fatals.
- `GET /autocomplete/{lang}/?query=heart&count=10` — JSON autocomplete
  (`DeCSLocatorAutoComplete`). Confirm `application/json` response.

## Verification

- `php bin/console about` shows Symfony 7.4.x and PHP 8.4.
- `composer update` completed with no unresolved conflicts; `composer.lock` regenerated.
- `php bin/console cache:clear` succeeds with no errors.
- No new deprecations originating from `src/` in `debug:container --deprecations` / `var/log/dev.log`.
- Both routes respond correctly in the running dev container (manual curl/browser check).
- (Optional) `vendor/bin/phpunit` runs clean — currently a no-op as there are no test cases.

## Risks & Notes

- **Low risk**: same-major bump, tiny custom surface, stable APIs only.
- The `dev_update_symfony` target uses `--with-all-dependencies`; it may bump minor/patch versions
  of non-Symfony transitive packages. Acceptable under "Symfony-only, minimal" — review the lock
  diff to confirm no surprise **major** bumps slipped in.
- `phpunit.xml.dist` uses the legacy PHPUnit 9 schema (`convertDeprecationsToExceptions`,
  `<listeners>`). Left as-is per scope decision; note as a candidate follow-up if PHPUnit is ever
  modernized.
- `AuxFunctions::isUTF8` uses `utf8_encode/decode` (deprecated since PHP 8.2) — pre-existing, PHP
  (not Symfony) concern, out of scope here.
- Rollback: revert `composer.json`, `composer.lock`, `symfony.lock` via git and re-run
  `composer install` in the container.
