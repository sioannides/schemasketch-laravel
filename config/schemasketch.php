<?php

return [

    // The SchemaSketch API. Change only for a self-hosted or staging copy.
    'api_url' => env('SCHEMASKETCH_API_URL', 'https://schemasketch.dev/api'),

    // A token from SchemaSketch's "Connect AI agent" dialog. `pull` needs a
    // "Read & edit" token; `diff` and `push` work with a read-only one, which
    // is the safer choice for CI.
    'token' => env('SCHEMASKETCH_TOKEN'),

    // The diagram this app is linked to (its id is the last part of the
    // diagram's link). Every command also takes --diagram to override it.
    'diagram' => env('SCHEMASKETCH_DIAGRAM'),

    // Framework tables left out of pull and diff.
    'ignore_tables' => [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens',
    ],

    // Where `pull` looks for Eloquent models to find relationships the
    // database has no foreign key for. Null means app/Models.
    'models_path' => null,

    // Seconds to wait for the SchemaSketch API.
    'timeout' => (int) env('SCHEMASKETCH_TIMEOUT', 15),

];
