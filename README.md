# schemasketch/laravel

Turn any Laravel app into a live [SchemaSketch](https://schemasketch.dev)
diagram, check it for drift, and generate migrations from your diagram.

> **Work in progress.** `pull` and `diff` work; `push` is next. See
> [docs/DESIGN.md](docs/DESIGN.md).

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

Coming next: `schemasketch:push` (the difference as one new migration).

Never edits existing migrations or runs them.

## License

MIT
