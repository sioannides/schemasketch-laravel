# schemasketch/laravel

[![Latest version](https://img.shields.io/packagist/v/schemasketch/laravel?label=version)](https://packagist.org/packages/schemasketch/laravel)
[![Tests](https://github.com/sioannides/schemasketch-laravel/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/sioannides/schemasketch-laravel/actions/workflows/tests.yml)
[![Downloads](https://img.shields.io/packagist/dt/schemasketch/laravel)](https://packagist.org/packages/schemasketch/laravel/stats)
[![PHP](https://img.shields.io/packagist/dependency-v/schemasketch/laravel/php)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License](https://img.shields.io/packagist/l/schemasketch/laravel)](LICENSE)
[![SchemaSketch status](https://img.shields.io/endpoint?url=https%3A%2F%2Fstatus.schemasketch.dev%2Fbadge.json)](https://status.schemasketch.dev)

Turn any Laravel app into a live [SchemaSketch](https://schemasketch.dev)
diagram, check it for drift, and generate migrations from your diagram.

> Early release (0.x): commands and options may still change before 1.0.
> Full guide: [schemasketch.dev/laravel-package](https://schemasketch.dev/laravel-package)

```bash
composer require schemasketch/laravel --dev
```

Create a token in SchemaSketch ("Connect AI agent", choose **Read & edit**)
and add it to `.env`:

```dotenv
SCHEMASKETCH_TOKEN=123|abc...
```

## `php artisan schemasketch:pull`

Sends your database's schema to a SchemaSketch diagram: tables, columns,
indexes, foreign keys, plus relationships your Eloquent models define that
have no foreign key. Schema only, never your data.

- First run: creates a diagram and prints its link and the
  `SCHEMASKETCH_DIAGRAM=…` line to add to `.env`.
- Later runs: merge into that diagram. The database decides each table's
  columns; tables, notes, colors and groups that exist only in the diagram
  are kept. You're shown what changes and asked first (`--force` skips the
  question).

| Option | |
| --- | --- |
| `--dry-run` | Print the DBML, upload nothing |
| `--output=schema.dbml` | Save the DBML to a file, upload nothing |
| `--diagram=ID` | Use this diagram instead of `SCHEMASKETCH_DIAGRAM` |
| `--name="Shop"` | Name for a new diagram (default: your app name) |
| `--connection=pgsql` | Read this database connection |
| `--no-models` | Skip relationships from Eloquent models |
| `-v` | List relationships left out (polymorphic, composite keys) and why |

## `php artisan schemasketch:diff`

Compares the linked diagram with your database and lists what differs:

```
+----------------------+-------+------------------+--------------------------------------------------+
|                      | Table | What             | Details                                          |
+----------------------+-------+------------------+--------------------------------------------------+
| In the diagram only  | posts | column published |                                                  |
| In the database only | users | column legacy_id |                                                  |
| Different            | users | column email     | not null: no in the database, yes in the diagram |
+----------------------+-------+------------------+--------------------------------------------------+
```

Rows in red would drop or narrow something if the database were made to
match the diagram. Types are compared by family (`int`/`bigint`/`int8` are
all integers), so driver spellings don't show up as differences.

| Exit code | Meaning |
| --- | --- |
| `0` | The database matches the diagram |
| `1` | There are differences |
| `2` | Couldn't check (no diagram, token, network, or the diagram's DBML has errors) |

That makes it a CI step. A **read-only** token is enough, and is the safer
choice there:

```yaml
- run: php artisan migrate --force
- run: php artisan schemasketch:diff
  env:
    SCHEMASKETCH_TOKEN: ${{ secrets.SCHEMASKETCH_TOKEN }}
    SCHEMASKETCH_DIAGRAM: ${{ vars.SCHEMASKETCH_DIAGRAM }}
```

| Option | |
| --- | --- |
| `--json` | Print the differences as JSON for scripts |
| `--diagram=ID` | Use this diagram instead of `SCHEMASKETCH_DIAGRAM` |
| `--connection=pgsql` | Read this database connection |

## `php artisan schemasketch:push`

Turns the differences `diff` finds into **one new migration**
(`database/migrations/…_schemasketch_sync.php`) whose `up()` makes the
database match the diagram and whose `down()` puts it back.

- Shows the migration and asks before writing it. If it drops a table or
  column, or narrows a type, it asks a second time.
- Never runs the migration and never edits existing ones. Run
  `php artisan migrate` yourself after reviewing it.
- Foreign keys are dropped first and added last, so tables can be created
  and dropped in any order.
- A column pointing at an auto-increment key is written as unsigned, as
  Laravel's `id()` is.
- Primary key and auto-increment changes on existing columns are listed
  for you to do by hand, not generated.
- New tables declare their foreign keys inside `Schema::create`.
- Enums work on every driver. On PostgreSQL, where Laravel can't
  `->change()` a column to an enum, the migration adds the check
  constraint itself.
- Works with a read-only token.

**SQLite note:** when a migration adds or drops a foreign key on an
existing table, Laravel rebuilds that table on SQLite and the rebuild
drops enum check constraints. `diff` then rightly reports those columns
as plain text. MySQL and PostgreSQL aren't affected.

| Option | |
| --- | --- |
| `--models` | Also create models for new tables, and add new relationships to your existing models |
| `--factories` | Also create factories for the new models (implies `--models`) |
| `--force` | Write without asking (also skips the second question) |
| `--path=database/migrations` | Where to write the migration |
| `--diagram=ID` | Use this diagram instead of `SCHEMASKETCH_DIAGRAM` |
| `--connection=pgsql` | Compare with this database connection |

### Models and factories

`--models` writes an Eloquent model for every table the push adds, with
`$fillable`, casts (booleans, decimals, JSON, dates), `SoftDeletes` and
`HasUuids` where they apply, and its relationships: `belongsTo`, `hasMany`,
`hasOne` for a unique column, and `belongsToMany` through a plain pivot table
(which gets no model of its own).

Models you already have only get the relationships this push adds, such as
`posts()` on `User` when a new `posts` table points at `users`, and only if
there's no method of that name yet. Nothing else in the file changes.

`--factories` adds a factory for each new model, with fake data picked by
column name and type. Foreign keys use the related model's factory, or an
existing row if that model has none.

You're shown every file and asked before anything is written. Models and
factories go to `app/Models` and `database/factories`; change `models_path`,
`models_namespace`, `factories_path` and `factories_namespace` in
`config/schemasketch.php` if yours live elsewhere.


## License

MIT
