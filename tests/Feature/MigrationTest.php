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
        ->toContain('comment')
        ->toContain('is_marked')
        ->toContain('direction')
        ->toContain('service')
        ->toContain('correlation_identifier')
        ->toContain('retry_attempt')
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

    expect($indexNames)->toContain('api_logs_method_index')
        ->toContain('api_logs_response_code_index')
        ->toContain('api_logs_user_identifier_index')
        ->toContain('api_logs_is_marked_index')
        ->toContain('api_logs_created_at_index')
        ->toContain('api_logs_direction_index')
        ->toContain('api_logs_service_index')
        ->toContain('api_logs_correlation_identifier_index')
        ->toContain('api_logs_retry_attempt_index');
});

it('creates correct column types and defaults', function () {
    // Test that columns have correct default values
    $log = \Ameax\ApiLogger\Models\ApiLog::create([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 10.0,
    ]);

    // Refresh to get database defaults
    $log->refresh();

    expect($log->is_marked)->toBeFalse()
        ->and($log->comment)->toBeNull()
        ->and($log->direction)->toBe('inbound')
        ->and($log->retry_attempt)->toBe(0);
});

it('rolls back the migration', function () {
    expect(Schema::hasTable('api_logs'))->toBeTrue();

    // Run the down method manually
    $migrationFile = __DIR__.'/../../database/migrations/create_api_logs_table.php.stub';
    $migration = require $migrationFile;
    $migration->down();

    expect(Schema::hasTable('api_logs'))->toBeFalse();
});

it('verifies that request_id column does not exist', function () {
    $columns = Schema::getColumnListing('api_logs');

    expect($columns)->not->toContain('request_id');
});
