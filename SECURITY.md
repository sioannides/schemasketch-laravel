# Security policy

## Supported versions

Fixes go into the newest release only. Until 1.0, that is the newest 0.x
release; update to it to get security fixes.

| Version | Supported |
|---|---|
| Newest release | Yes |
| Anything older | No |

## Reporting a vulnerability

Please don't open a public issue. Report it privately through GitHub:
**Security → Report a vulnerability** on this repository
(https://github.com/sioannides/schemasketch-laravel/security/advisories/new).

Include what you found, how to reproduce it, and which version you used.
You'll get a reply within 5 working days. Once a fix is released, the
advisory is published with credit to you, unless you'd rather not be named.

## What the package does that matters for security

- It reads your database schema (tables, columns, indexes, foreign keys),
  never the rows in them.
- `pull` sends that schema, as DBML, to your SchemaSketch diagram, so it
  needs a read-write token. `diff` and `push` only read the diagram, so a
  read-only token is enough for them.
- The token is read from `SCHEMASKETCH_TOKEN`. Keep it in `.env`, not in
  your code.
- `push` writes migration, model and factory files into your app. It never
  runs migrations; you review and run them yourself.
