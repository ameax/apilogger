<?php

declare(strict_types=1);

use Ameax\ApiLogger\Storage\DatabaseStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('apilogger.retention.days', 30);
    Config::set('apilogger.retention.error_days', 90);
    Config::set('apilogger.storage.driver', 'database');
    Config::set('apilogger.storage.database.connection', 'testing');

    // Helper to insert logs directly using the test connection
    $this->connection = app('db')->connection('testing');
});

it('cleans old logs using config values', function () {
    // Create old normal log (should be deleted - older than 30 days)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(31),
        'updated_at' => Carbon::now(),
    ]);

    // Create recent normal log (should be kept - only 29 days old)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(29),
        'updated_at' => Carbon::now(),
    ]);

    // Create old error log within error retention (should be kept - only 60 days old, error_days=90)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 500,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(60),
        'updated_at' => Carbon::now(),
    ]);

    // Create very old error log (should be deleted - older than 90 days)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 500,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(91),
        'updated_at' => Carbon::now(),
    ]);

    expect($this->connection->table('api_logs')->count())->toBe(4);

    $storage = new DatabaseStorage(app('db'), [
        'table' => 'api_logs',
        'connection' => 'testing',
    ]);
    $deleted = $storage->clean(30, 90);

    expect($deleted)->toBe(2);
    expect($this->connection->table('api_logs')->count())->toBe(2);
});

it('accepts custom retention days via options', function () {
    // Create log that is 15 days old (should be deleted with days=10)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(15),
        'updated_at' => Carbon::now(),
    ]);

    // Create log that is 5 days old (should be kept with days=10)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(5),
        'updated_at' => Carbon::now(),
    ]);

    $storage = new DatabaseStorage(app('db'), [
        'table' => 'api_logs',
        'connection' => 'testing',
    ]);
    $deleted = $storage->clean(10, 20);

    expect($deleted)->toBe(1);
    expect($this->connection->table('api_logs')->count())->toBe(1);
});

it('does not delete preserved logs (is_marked=true)', function () {
    // Create old but marked log (should be kept)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => true,
        'created_at' => Carbon::now()->subDays(100),
        'updated_at' => Carbon::now(),
    ]);

    $storage = new DatabaseStorage(app('db'), [
        'table' => 'api_logs',
        'connection' => 'testing',
    ]);
    $deleted = $storage->clean(30, 90);

    expect($deleted)->toBe(0);
    expect($this->connection->table('api_logs')->count())->toBe(1);
});

it('does not delete logs with comments', function () {
    // Create old log with comment (should be kept)
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'comment' => 'Important log to keep',
        'created_at' => Carbon::now()->subDays(100),
        'updated_at' => Carbon::now(),
    ]);

    $storage = new DatabaseStorage(app('db'), [
        'table' => 'api_logs',
        'connection' => 'testing',
    ]);
    $deleted = $storage->clean(30, 90);

    expect($deleted)->toBe(0);
    expect($this->connection->table('api_logs')->count())->toBe(1);
});

it('command asks for confirmation without force flag', function () {
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now()->subDays(31),
        'updated_at' => Carbon::now(),
    ]);

    $this->artisan('api-logger:clean')
        ->expectsConfirmation('Do you want to proceed with cleaning old logs?', 'no')
        ->expectsOutput('Operation cancelled.')
        ->assertExitCode(0);

    expect($this->connection->table('api_logs')->count())->toBe(1);
});

it('command reports zero deletions when no old logs exist', function () {
    $this->connection->table('api_logs')->insert([
        'method' => 'GET',
        'endpoint' => '/api/test',
        'response_code' => 200,
        'response_time_ms' => 100,
        'is_marked' => false,
        'created_at' => Carbon::now(),
        'updated_at' => Carbon::now(),
    ]);

    $this->artisan('api-logger:clean', ['--force' => true])
        ->expectsOutputToContain('Successfully deleted 0 old log entries.')
        ->assertExitCode(0);

    expect($this->connection->table('api_logs')->count())->toBe(1);
});
