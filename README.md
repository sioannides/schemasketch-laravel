# schemasketch/laravel

Turn any Laravel app into a live [SchemaSketch](https://schemasketch.dev)
diagram, check it for drift, and generate migrations from your diagram.

> **Work in progress.** The commands aren't built yet; see
> [docs/DESIGN.md](docs/DESIGN.md) for what's planned.

```bash
composer require schemasketch/laravel --dev
```

Planned commands:

- `php artisan schemasketch:pull`: your database's schema → a SchemaSketch diagram
- `php artisan schemasketch:diff`: compare the diagram with your database (CI-friendly exit codes)
- `php artisan schemasketch:push`: the difference → one new migration

Reads schema only, never your data. Never edits existing migrations or runs
them.

## License

MIT
