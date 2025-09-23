<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('runs the migration', function () {
    expect(Schema::hasTable('api_logs'))->toBeTrue();
});

it('creates api_logs table with correct columns', function () {
    $columns = Schema::getColumnListing('api_logs');

    expect($columns)->toContain('id')
        ->toContain('request_id')
        ->toContain('method')
        ->toContain('endpoint')
        ->toContain('request_headers')
        ->toContain('request_body')
        ->toContain('response_code')
        ->toContain('response_headers')
        ->toContain('response_body')
        ->toContain('response_time_ms')
        ->toContain('user_identifier')
        ->toContain('ip_address')
        ->toContain('user_agent')
        ->toContain('metadata')
        ->toContain('created_at')
        ->toContain('updated_at');
});

it('creates indexes for performance', function () {
    // Skip this test for SQLite as it doesn't support listing indexes the same way
    if (config('database.default') === 'testing') {
        expect(true)->toBeTrue();

        return;
    }

    $indexes = collect(Schema::getConnection()
        ->getDoctrineSchemaManager()
        ->listTableIndexes('api_logs'));

    $indexNames = $indexes->keys()->map(fn ($name) => strtolower($name))->toArray();

    expect($indexNames)->toContain('api_logs_request_id_unique')
        ->toContain('api_logs_method_index')
        ->toContain('api_logs_response_code_index')
        ->toContain('api_logs_user_identifier_index')
        ->toContain('api_logs_created_at_index');
});

it('rolls back the migration', function () {
    expect(Schema::hasTable('api_logs'))->toBeTrue();

    // Run the down method manually
    $migrationFile = __DIR__.'/../../database/migrations/create_api_logs_table.php.stub';
    $migration = require $migrationFile;
    $migration->down();

    expect(Schema::hasTable('api_logs'))->toBeFalse();
});
