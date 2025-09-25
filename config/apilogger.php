<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | API Logger Enabled
    |--------------------------------------------------------------------------
    |
    | This option determines whether the API logger is enabled.
    | You can set this to false to completely disable logging.
    |
    */
    'enabled' => env('API_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Logging Level
    |--------------------------------------------------------------------------
    |
    | This option determines how much detail is logged for each request.
    |
    | Supported: "none", "basic", "detailed", "full"
    | - none: Logging is disabled
    | - basic: Log method, endpoint, status code, response time
    | - detailed: Basic + headers, user info, IP address
    | - full: Detailed + request/response bodies
    |
    */
    'level' => env('API_LOGGER_LEVEL', 'detailed'),

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how and where the API logs are stored.
    |
    */
    'storage' => [
        // Storage driver: "database", "jsonline"
        'driver' => env('API_LOGGER_STORAGE_DRIVER', 'database'),

        // Database storage specific settings
        'database' => [
            // Database connection to use for storing logs
            // You can specify a separate connection (e.g., 'sqlite') while your main app uses 'mysql'
            // Set to null or omit to use the default database connection
            // Example: 'sqlite' to use SQLite, 'mysql' to use MySQL, 'pgsql' for PostgreSQL
            'connection' => env('API_LOGGER_DB_CONNECTION', config('database.default')),
            'table' => 'api_logs',
        ],

        // JSON Lines storage specific settings
        'jsonline' => [
            'path' => env('API_LOGGER_JSONLINE_PATH', storage_path('logs/api')),
            'filename_format' => 'api-{date}.jsonl', // {date} will be replaced with Y-m-d
            'rotate_daily' => true,
            'compress_old_files' => true, // Compress files older than 1 day
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | Configure how long logs are retained before automatic cleanup.
    |
    */
    'retention' => [
        // Number of days to keep normal logs (2xx, 3xx responses)
        'days' => env('API_LOGGER_RETENTION_DAYS', 30),

        // Number of days to keep error logs (4xx, 5xx responses)
        'error_days' => env('API_LOGGER_ERROR_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy & Sanitization
    |--------------------------------------------------------------------------
    |
    | Configure which fields should be sanitized to protect sensitive data.
    |
    */
    'privacy' => [
        // Fields to completely remove from logs
        'exclude_fields' => [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'cvv',
            'ssn',
        ],

        // Fields to mask (show first/last few characters only)
        'mask_fields' => [
            'email', // Shows: ex****@****.com
            'phone', // Shows: ***-***-1234
            'authorization', // Shows: Bearer ****
        ],

        // Masking strategy: "partial", "full", "hash"
        'masking_strategy' => env('API_LOGGER_MASKING_STRATEGY', 'partial'),

        // Headers to exclude from logging
        'exclude_headers' => [
            'Authorization',
            'Cookie',
            'X-CSRF-Token',
            'X-Api-Key',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Configure performance-related settings.
    |
    */
    'performance' => [
        // Use queue for storing logs (reduces API response time)
        'use_queue' => env('API_LOGGER_USE_QUEUE', false),

        // Queue name for log processing
        'queue_name' => env('API_LOGGER_QUEUE', 'default'),

        // Batch size for bulk operations
        'batch_size' => env('API_LOGGER_BATCH_SIZE', 100),

        // Maximum execution time for logging operations (milliseconds)
        'timeout' => env('API_LOGGER_TIMEOUT', 1000),

        // Maximum payload size to log (in bytes, 0 = unlimited)
        'max_body_size' => env('API_LOGGER_MAX_BODY_SIZE', 65536), // 64KB
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific logging features independently.
    |
    */
    'features' => [
        // Inbound API logging (incoming requests to your application)
        'inbound' => [
            'enabled' => env('API_LOGGER_INBOUND_ENABLED', true),
        ],

        // Outbound API logging (external API calls made by your application)
        'outbound' => [
            'enabled' => env('API_LOGGER_OUTBOUND_ENABLED', false),

            // Auto-register middleware for all Guzzle clients
            'auto_register' => env('API_LOGGER_OUTBOUND_AUTO_REGISTER', false),

            // Filtering configuration for outbound requests
            'filters' => [
                // Include filters (if specified, only matching requests are logged)
                'include_hosts' => [
                    // '*.stripe.com',
                    // 'api.paypal.com',
                ],
                'include_services' => [
                    // 'App\Services\StripeService',
                ],
                'include_patterns' => [
                    // '/api/*',
                    // '/v1/*',
                ],
                'include_methods' => [
                    // 'POST', 'PUT', 'DELETE',
                ],

                // Exclude filters (takes precedence over include filters)
                'exclude_hosts' => [
                    'localhost',
                    '127.0.0.1',
                    '*.local',
                ],
                'exclude_services' => [
                    // 'App\Services\InternalCache',
                ],
                'exclude_patterns' => [
                    // '/health',
                    // '/metrics',
                ],
                'exclude_methods' => [
                    // 'OPTIONS',
                ],

                // Cache filter results for performance
                'cache_enabled' => env('API_LOGGER_OUTBOUND_CACHE', true),
                'cache_ttl' => 60, // seconds

                // Custom filter callbacks
                'include_callback' => null, // callable that returns bool
                'exclude_callback' => null, // callable that returns bool
            ],

            // Per-service configuration
            'services' => [
                'configs' => [
                    // 'App\Services\StripeService' => [
                    //     'enabled' => true,
                    //     'log_level' => 'full',
                    //     'sanitize_fields' => ['api_key', 'customer_id'],
                    //     'timeout_ms' => 5000,
                    //     'always_log_errors' => true,
                    // ],
                ],
            ],
        ],

        // Correlation ID configuration
        'correlation' => [
            'enabled' => env('API_LOGGER_CORRELATION_ENABLED', true),

            // Header name for correlation ID
            'header_name' => env('API_LOGGER_CORRELATION_HEADER', 'X-Correlation-ID'),

            // Headers to check for existing correlation ID
            'headers' => [
                'X-Correlation-ID',
                'X-Request-ID',
                'X-Trace-ID',
            ],

            // Propagate correlation ID to outbound requests
            'propagate' => env('API_LOGGER_CORRELATION_PROPAGATE', true),

            // Add correlation ID to response headers
            'add_to_response' => env('API_LOGGER_CORRELATION_RESPONSE', true),

            // Generation method: 'uuid', 'ulid', 'timestamp'
            'generation_method' => env('API_LOGGER_CORRELATION_METHOD', 'uuid'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the logging middleware is registered.
    |
    */
    'middleware' => [
        // Add to global middleware stack
        'global' => env('API_LOGGER_MIDDLEWARE_GLOBAL', false),

        // Add to 'api' middleware group
        'api_group' => env('API_LOGGER_MIDDLEWARE_API_GROUP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filtering Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which requests should be logged.
    |
    */
    'filters' => [
        // Routes to include (empty array = all routes)
        // Supports wildcards: 'api/*', 'admin/*'
        'include_routes' => [
            // 'api/*',
        ],

        // Routes to exclude from logging
        // Takes precedence over include_routes
        'exclude_routes' => [
            'health',
            'health/*',
            '_debugbar/*',
            'telescope/*',
            'horizon/*',
            'nova-api/*',
        ],

        // HTTP methods to log (empty array = all methods)
        'include_methods' => [
            // 'GET', 'POST', 'PUT', 'PATCH', 'DELETE'
        ],

        // HTTP methods to exclude from logging
        'exclude_methods' => [
            'OPTIONS',
            'HEAD',
        ],

        // Status codes to include (empty array = all codes)
        'include_status_codes' => [
            // 200, 201, 400, 401, 403, 404, 500
        ],

        // Status codes to exclude from logging
        'exclude_status_codes' => [
            // 304, // Not Modified
        ],

        // Minimum response time to log (milliseconds, 0 = log all)
        'min_response_time' => env('API_LOGGER_MIN_RESPONSE_TIME', 0),

        // Always log errors (4xx, 5xx) regardless of other filters
        'always_log_errors' => env('API_LOGGER_ALWAYS_LOG_ERRORS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Enrichment
    |--------------------------------------------------------------------------
    |
    | Configure additional data to capture with each request.
    |
    */
    'enrichment' => [
        // Capture authenticated user information
        'capture_user' => true,

        // User identifier field (e.g., 'id', 'uuid', 'email')
        'user_identifier' => 'id',

        // Capture request IP address
        'capture_ip' => true,

        // Capture user agent string
        'capture_user_agent' => true,

        // Capture request duration
        'capture_duration' => true,

        // Capture memory usage
        'capture_memory' => false,

        // Custom enrichment callback (return array of additional data)
        'custom_callback' => null, // Example: '\App\Services\ApiLogger::enrich'
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    |
    | Configure export functionality for logs.
    |
    */
    'export' => [
        // Supported export formats
        'formats' => ['json', 'csv', 'jsonl'],

        // Maximum number of records per export
        'max_records' => env('API_LOGGER_EXPORT_MAX_RECORDS', 10000),

        // Temporary storage path for exports
        'temp_path' => storage_path('app/temp/api-logs'),
    ],
];
