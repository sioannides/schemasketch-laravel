# Changelog

Versions follow [Semantic Versioning](https://semver.org). While the
version is 0.x, a minor release (0.1 → 0.2) may change commands or options;
patch releases (0.1.0 → 0.1.1) only fix things. Work happens on the `dev`
branch: add changes under "Unreleased" as you make them. `scripts/release.sh`
moves `main` to `dev`, dates that section and tags the release.

## Unreleased

- `push --models` creates Eloquent models for new tables (fillable, casts,
  soft deletes, UUID keys, relationships) and adds new relationships to your
  existing models, without touching anything else in them.
- `push --factories` creates factories for the new models.
- New config: `models_namespace`, `factories_path`, `factories_namespace`.

## 0.1.1 (2026-10-30)

- Badges in the README: version, tests, downloads, PHP and Laravel versions, license, service status.

## 0.1.0 (2026-10-30)

First release.

- `schemasketch:pull` sends your database's schema to a diagram, including
  relationships your Eloquent models define without a foreign key. On a
  linked diagram it merges, keeping planned tables, notes, colors and
  groups.
- `schemasketch:diff` compares the diagram with your database. Exit codes
  0 (in step), 1 (different) and 2 (couldn't check) make it a CI step;
  `--json` for scripts.
- `schemasketch:push` turns the differences into one new migration with a
  matching `down()`. Asks before writing, and again if it drops anything.
  Never runs the migration.
- MySQL/MariaDB, PostgreSQL and SQLite; Laravel 12 and 13; PHP 8.2+.
