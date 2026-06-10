# 2026-06-10 — Upgrade Symfony 7.2 → 7.4 LTS

## Summary

Upgraded every `symfony/*` package from `7.2.*` to the **7.4 LTS** branch (installed 7.4.13),
the long-term-support release of the 7.x series (bug fixes to Nov 2028, security to Nov 2029).
Same-major upgrade → no BC breaks, only deprecations. Scope kept minimal: Symfony packages only,
recipe-managed config left as-is, PHPUnit kept at ^9.6.

## Changes

- **`composer.json`** — bumped all ~30 `symfony/*` constraints `7.2.*` → `7.4.*` (in `require` and
  `require-dev`) plus `extra.symfony.require: "7.2.*"` → `"7.4.*"`. Left untouched: `symfony/flex`,
  `symfony/monolog-bundle`, `symfony/phpunit-bridge` (already `^7.4.8`), `php >=8.2`, `twig/*`,
  `phpunit/phpunit ^9.6`.
- **`composer.lock` / `symfony.lock`** — regenerated via
  `composer update "symfony/*" --with-all-dependencies` inside the Docker dev container. All Symfony
  packages now `v7.4.x`; no surprise major bumps in transitive deps.
- **`config/packages/property_info.yaml`** (new) — auto-scaffolded by Flex from the updated
  `symfony/property-info` recipe; sets `with_constructor_extractor: true` (the 7.4 default, silences
  a deprecation).
- **`config/reference.php`** (new) — auto-scaffolded dev-only IDE-autocomplete helper (no runtime
  effect). Candidate to `.gitignore` if generated files aren't wanted in VCS.

## Verification (Docker dev container, PHP 8.4.10)

- `bin/console about` → Symfony **7.4.13**.
- `bin/console cache:clear` → OK.
- `bin/console debug:container --deprecations` → **0 deprecations from `src/`**; 6 dev-only
  (`when@dev`) deprecations remain in recipe-scaffolded routing imports (`errors.xml`, `wdt.xml`,
  `profiler.xml` → suggested `.php` equivalents). Left as-is per scope decision.
- Smoke tests over HTTP (host :8030):
  - `GET /autocomplete/en/?query=heart&count=5` → 200, `application/json`, real DeCS data.
  - `GET /locate/?lang=pt` → 200, `text/html`, full page rendered.

## Decisions

- Leave recipe-managed config as-is (no `composer recipes:update`).
- Symfony-only, minimal scope (keep PHPUnit ^9.6, no non-Symfony major bumps).

## Follow-ups (not done)

- Optionally `.gitignore` `config/reference.php` (generated dev helper).
- Optionally migrate dev-only XML routing imports to `.php` to clear the 6 remaining deprecations.
- Porting DeCSLocator tests from iahx-opac was discussed but deferred (architectural mismatch:
  this project uses raw `file_get_contents` vs iahx-opac's injectable `HttpFetchService`).

## Plan

[001-upgrade-symfony-7-4.md](../plans/001-upgrade-symfony-7-4.md)
