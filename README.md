# schemasketch/laravel

Turn any Laravel app into a live [SchemaSketch](https://schemasketch.dev)
diagram, check it for drift, and generate migrations from your diagram.

> **Work in progress.** `pull`, `diff` and `push` work; not on Packagist yet.

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
| `--force` | Write without asking (also skips the second question) |
| `--path=database/migrations` | Where to write the migration |
| `--diagram=ID` | Use this diagram instead of `SCHEMASKETCH_DIAGRAM` |
| `--connection=pgsql` | Compare with this database connection |

Coming next: `--models` and `--factories` on `push`.


## License

MIT
