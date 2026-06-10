# Current Feature: Upgrade Symfony 7.2 → 7.4 LTS

## Status

<!-- Not Started|In Progress|Completed -->

Completed

## Goals

<!-- Goals & requirements -->

- Upgrade all `symfony/*` components from `7.2.*` to `7.4.*` (the LTS release of the 7.x branch)
- Update Symfony Flex constraint `extra.symfony.require` from `7.2.*` to `7.4.*`
- Resolve any deprecations / BC breaks introduced between 7.2 and 7.4
- Keep PHP requirement compatible (Symfony 7.x requires PHP 8.2+, already satisfied)
- Ensure the application builds, boots, and tests pass after the upgrade

## Notes

<!-- Any extra notes -->

- Loaded inline from `/feature` description: "Update the Symfony framework from version 7.2 to the last LTS 7.4 version".
- Symfony's LTS releases are the `.4` minor of each major; **7.4** is the LTS of the 7.x series (released Nov 2025), supported alongside the 8.x major.
- Upgrade spans two minors (7.2 → 7.3 → 7.4). Per Symfony policy, no BC breaks within a major, only deprecations — but deprecations from 7.x become removals in 8.0, so this is also prep for an eventual 8.x jump.
- All `symfony/*` requires are pinned to `7.2.*` in `composer.json` (both `require` and `require-dev`), plus the Flex constraint in `extra.symfony.require`.
- `symfony/phpunit-bridge` is already at `^7.4.8`; PHPUnit is `^9.6` (note: Symfony 7.4 ships recipes assuming newer PHPUnit, may warrant a follow-up).

## Detailed Plan

<!-- Link to detailed plan file -->

[001-upgrade-symfony-7-4.md](.ai/plans/001-upgrade-symfony-7-4.md)

## History

<!-- Keep this updated. Earliest to latest -->

- 2026-06-10: Starting Upgrade Symfony 7.2 → 7.4 LTS — following plan [001-upgrade-symfony-7-4.md](.ai/plans/001-upgrade-symfony-7-4.md)
