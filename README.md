# schemasketch/laravel

Turn any Laravel app into a live [SchemaSketch](https://schemasketch.dev)
diagram, check it for drift, and generate migrations from your diagram.

> **Work in progress.** `pull` works; `diff` and `push` are next. See
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

Coming next: `schemasketch:diff` (compare the diagram with your database,
CI-friendly) and `schemasketch:push` (the difference as one new migration).

Never edits existing migrations or runs them.

## License

MIT
