# Changelog

Versions follow [Semantic Versioning](https://semver.org). While the
version is 0.x, a minor release (0.1 → 0.2) may change commands or options;
patch releases (0.1.0 → 0.1.1) only fix things. Work happens on the `dev`
branch: add changes under "Unreleased" as you make them. `scripts/release.sh`
dates that section and opens a pull request to `main`; once it is merged,
`scripts/release.sh tag` tags the release on `main`.

## Unreleased

## 0.3.2 (2026-11-03)

- Security: `pull`, `diff` and `push` no longer pass terminal control
  characters through to your screen. Names, notes and messages from a
  diagram, the database or the API could otherwise clear the screen, fake
  output or hide text. Such characters now show as visible escapes like
  `\x1B`; files are written unchanged, and `diff --json` stays valid JSON.

## 0.3.1 (2026-11-03)

- Security: `push` now escapes table, column, index and foreign key names
  everywhere it writes them into migrations, models and factories, and turns
  them into plain PHP names for classes and methods. Before, someone who
  could edit your diagram could put a name built to inject PHP code into
  the files `push` writes, which would run when you migrate or load them.
  Update if other people can edit your diagrams.

## 0.3.0 (2026-11-03)

- Renames: tables and columns renamed in SchemaSketch show as "Renamed" in
  `diff`, and `push` writes `Schema::rename` / `renameColumn` instead of a
  drop plus an add, so the data stays. Uses the `renamed_from` history the
  schema endpoint now returns.

## 0.2.0 (2026-11-02)

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
