<?php

it('merges the default config', function () {
    expect(config('schemasketch.api_url'))->toBe('https://schemasketch.dev/api')
        ->and(config('schemasketch.timeout'))->toBe(15)
        ->and(config('schemasketch.ignore_tables'))->toContain('migrations');
});

it('lets the config file be published', function () {
    $this->artisan('vendor:publish', ['--tag' => 'schemasketch-config', '--force' => true])->assertSuccessful();
    expect(file_exists(config_path('schemasketch.php')))->toBeTrue();
    unlink(config_path('schemasketch.php'));
});
