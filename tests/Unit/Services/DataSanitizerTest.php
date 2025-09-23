<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Tests\Unit\Services;

use Ameax\ApiLogger\Services\DataSanitizer;

beforeEach(function () {
    $this->sanitizer = new DataSanitizer([
        'privacy' => [
            'exclude_fields' => [
                'password',
                'password_confirmation',
                'token',
                'api_key',
                'secret',
            ],
            'mask_fields' => [
                'email',
                'phone',
                'authorization',
            ],
            'masking_strategy' => 'partial',
            'exclude_headers' => [
                'Authorization',
                'Cookie',
                'X-Api-Key',
            ],
        ],
    ]);
});

describe('DataSanitizer', function () {
    test('excludes sensitive fields from arrays', function () {
        $data = [
            'username' => 'john_doe',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'email' => 'john@example.com',
            'api_key' => 'abc123xyz',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['username'])->toBe('john_doe');
        expect($sanitized['password'])->toBe('[REDACTED]');
        expect($sanitized['password_confirmation'])->toBe('[REDACTED]');
        expect($sanitized['api_key'])->toBe('[REDACTED]');
        expect($sanitized['email'])->not->toBe('john@example.com'); // Should be masked
    });

    test('masks fields with partial strategy', function () {
        $data = [
            'email' => 'john.doe@example.com',
            'phone' => '555-123-4567',
            'name' => 'John Doe',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['name'])->toBe('John Doe'); // Not masked
        expect($sanitized['email'])->toContain('@');
        expect($sanitized['email'])->toContain('*');
        expect($sanitized['phone'])->toContain('*');
        expect($sanitized['phone'])->toContain('4567'); // Last 4 digits visible
    });

    test('sanitizes nested arrays recursively', function () {
        $data = [
            'user' => [
                'name' => 'John',
                'password' => 'secret',
                'profile' => [
                    'email' => 'john@example.com',
                    'api_key' => 'xyz789',
                ],
            ],
            'token' => 'bearer123',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['user']['name'])->toBe('John');
        expect($sanitized['user']['password'])->toBe('[REDACTED]');
        expect($sanitized['user']['profile']['email'])->toContain('*');
        expect($sanitized['user']['profile']['api_key'])->toBe('[REDACTED]');
        expect($sanitized['token'])->toBe('[REDACTED]');
    });

    test('sanitizes headers correctly', function () {
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer secret-token-123',
            'Cookie' => 'session=abc123',
            'X-Api-Key' => 'my-api-key',
            'User-Agent' => 'Mozilla/5.0',
        ];

        $sanitized = $this->sanitizer->sanitizeHeaders($headers);

        expect($sanitized['Content-Type'])->toBe('application/json');
        expect($sanitized['User-Agent'])->toBe('Mozilla/5.0');
        expect($sanitized['Authorization'])->toBe('[REDACTED]');
        expect($sanitized['Cookie'])->toBe('[REDACTED]');
        expect($sanitized['X-Api-Key'])->toBe('[REDACTED]');
    });

    test('handles different masking strategies', function () {
        $data = ['email' => 'test@example.com'];

        // Test partial masking (default)
        $sanitized = $this->sanitizer->sanitize($data);
        expect($sanitized['email'])->toContain('*');
        expect($sanitized['email'])->toContain('@');

        // Test full masking
        $this->sanitizer->setMaskingStrategy('full');
        $sanitized = $this->sanitizer->sanitize($data);
        expect($sanitized['email'])->toMatch('/^\*+$/');

        // Test hash masking
        $this->sanitizer->setMaskingStrategy('hash');
        $sanitized = $this->sanitizer->sanitize($data);
        expect($sanitized['email'])->toMatch('/^[a-f0-9]{16}$/');
    });

    test('masks email addresses correctly', function () {
        $emails = [
            'john@example.com',
            'a@b.co',
            'very.long.email@subdomain.example.org',
        ];

        foreach ($emails as $email) {
            $data = ['email' => $email];
            $sanitized = $this->sanitizer->sanitize($data);

            expect($sanitized['email'])->toContain('@');
            expect($sanitized['email'])->toContain('*');
            expect($sanitized['email'])->not->toBe($email);
        }
    });

    test('masks phone numbers correctly', function () {
        $phones = [
            '555-123-4567',
            '+1 (555) 123-4567',
            '5551234567',
            '123-456-7890',
        ];

        foreach ($phones as $phone) {
            $data = ['phone' => $phone];
            $sanitized = $this->sanitizer->sanitize($data);

            expect($sanitized['phone'])->toContain('*');
            if (strlen($phone) >= 10) {
                expect($sanitized['phone'])->toMatch('/\d{4}$/'); // Last 4 digits
            }
        }
    });

    test('handles Bearer tokens specially', function () {
        $data = [
            'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['authorization'])->toBe('Bearer ********');
    });

    test('sanitizes JSON strings', function () {
        $jsonData = json_encode([
            'user' => 'john',
            'password' => 'secret123',
            'email' => 'john@example.com',
        ]);

        $sanitized = $this->sanitizer->sanitize($jsonData);

        expect($sanitized)->toBeString();
        $decoded = json_decode($sanitized, true);
        expect($decoded['user'])->toBe('john');
        expect($decoded['password'])->toBe('[REDACTED]');
        expect($decoded['email'])->toContain('*');
    });

    test('sanitizes objects', function () {
        $object = (object) [
            'username' => 'john',
            'password' => 'secret',
            'profile' => (object) [
                'email' => 'john@example.com',
            ],
        ];

        $sanitized = $this->sanitizer->sanitize($object);

        expect($sanitized)->toBeObject();
        expect($sanitized->username)->toBe('john');
        expect($sanitized->password)->toBe('[REDACTED]');
        expect($sanitized->profile->email)->toContain('*');
    });

    test('handles snake_case and camelCase field names', function () {
        $data = [
            'user_password' => 'secret1',
            'userPassword' => 'secret2',
            'password_confirmation' => 'secret3',
            'passwordConfirmation' => 'secret4',
            'api_key' => 'key1',
            'apiKey' => 'key2',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['user_password'])->toBe('[REDACTED]');
        expect($sanitized['userPassword'])->toBe('[REDACTED]');
        expect($sanitized['password_confirmation'])->toBe('[REDACTED]');
        expect($sanitized['passwordConfirmation'])->toBe('[REDACTED]');
        expect($sanitized['api_key'])->toBe('[REDACTED]');
        expect($sanitized['apiKey'])->toBe('[REDACTED]');
    });

    test('can add custom exclude fields', function () {
        $this->sanitizer->addExcludeField('custom_secret');

        $data = [
            'custom_secret' => 'hidden',
            'normal_field' => 'visible',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['custom_secret'])->toBe('[REDACTED]');
        expect($sanitized['normal_field'])->toBe('visible');
    });

    test('can add custom mask fields', function () {
        $this->sanitizer->addMaskField('username');

        $data = [
            'username' => 'johndoe123',
            'name' => 'John Doe',
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['username'])->toContain('*');
        expect($sanitized['name'])->toBe('John Doe');
    });

    test('can add custom exclude headers', function () {
        $this->sanitizer->addExcludeHeader('X-Custom-Secret');

        $headers = [
            'X-Custom-Secret' => 'hidden',
            'X-Custom-Public' => 'visible',
        ];

        $sanitized = $this->sanitizer->sanitizeHeaders($headers);

        expect($sanitized['X-Custom-Secret'])->toBe('[REDACTED]');
        expect($sanitized['X-Custom-Public'])->toBe('visible');
    });

    test('returns configuration', function () {
        $config = $this->sanitizer->getConfiguration();

        expect($config)->toHaveKeys([
            'exclude_fields',
            'mask_fields',
            'masking_strategy',
            'exclude_headers',
        ]);

        expect($config['exclude_fields'])->toContain('password');
        expect($config['mask_fields'])->toContain('email');
        expect($config['masking_strategy'])->toBe('partial');
    });

    test('handles non-string values when masking', function () {
        $data = [
            'email' => 123, // Non-string
            'phone' => null, // Null
            'authorization' => true, // Boolean
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized['email'])->toBe('[REDACTED]');
        expect($sanitized['phone'])->toBe('[REDACTED]');
        expect($sanitized['authorization'])->toBe('[REDACTED]');
    });

    test('preserves data types for non-sensitive fields', function () {
        $data = [
            'count' => 42,
            'active' => true,
            'ratio' => 3.14,
            'items' => ['a', 'b', 'c'],
            'metadata' => null,
        ];

        $sanitized = $this->sanitizer->sanitize($data);

        expect($sanitized)->toBe($data);
    });
});
