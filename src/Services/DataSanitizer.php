<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services;

use Illuminate\Support\Str;

class DataSanitizer
{
    protected array $excludeFields;

    protected array $maskFields;

    protected string $maskingStrategy;

    protected array $excludeHeaders;

    protected string $redactedPlaceholder = '[REDACTED]';

    protected string $maskCharacter = '*';

    public function __construct(array $config = [])
    {
        $privacyConfig = $config['privacy'] ?? [];

        $this->excludeFields = $privacyConfig['exclude_fields'] ?? [
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
        ];

        $this->maskFields = $privacyConfig['mask_fields'] ?? [
            'email',
            'phone',
            'authorization',
        ];

        $this->maskingStrategy = $privacyConfig['masking_strategy'] ?? 'partial';

        $this->excludeHeaders = array_map(
            'strtolower',
            $privacyConfig['exclude_headers'] ?? [
                'Authorization',
                'Cookie',
                'X-CSRF-Token',
                'X-Api-Key',
            ]
        );
    }

    /**
     * Sanitize request/response data.
     *
     * @param  mixed  $data  The data to sanitize
     * @param  string  $context  The context ('request' or 'response')
     */
    public function sanitize(mixed $data, string $context = 'request'): mixed
    {
        if (is_array($data)) {
            return $this->sanitizeArray($data);
        }

        if (is_object($data)) {
            return $this->sanitizeObject($data);
        }

        if (is_string($data)) {
            // Try to decode JSON strings
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $sanitized = $this->sanitizeArray($decoded);

                return json_encode($sanitized);
            }
        }

        return $data;
    }

    /**
     * Sanitize request/response body.
     *
     * @param  mixed  $body  The body data to sanitize
     */
    public function sanitizeBody(mixed $body): mixed
    {
        return $this->sanitize($body);
    }

    /**
     * Sanitize headers array.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);

            // Check if header should be excluded
            if (in_array($lowerKey, $this->excludeHeaders, true)) {
                $sanitized[$key] = $this->redactedPlaceholder;

                continue;
            }

            // Check if header should be masked
            foreach ($this->maskFields as $maskField) {
                if (str_contains($lowerKey, strtolower($maskField))) {
                    $sanitized[$key] = $this->maskValue($value);

                    continue 2;
                }
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Sanitize an array recursively.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeArray(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Convert numeric keys to string for checking
            $keyStr = (string) $key;

            // Check if field should be excluded
            if ($this->shouldExcludeField($keyStr)) {
                $sanitized[$key] = $this->redactedPlaceholder;

                continue;
            }

            // Check if field should be masked
            if ($this->shouldMaskField($keyStr)) {
                $sanitized[$key] = $this->maskValue($value);

                continue;
            }

            // Recursively sanitize nested data
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif (is_object($value)) {
                $sanitized[$key] = $this->sanitizeObject($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize an object.
     */
    protected function sanitizeObject(object $data): object
    {
        // Convert to array, sanitize, and convert back
        $array = json_decode(json_encode($data), true);

        if (! is_array($array)) {
            return $data;
        }

        $sanitized = $this->sanitizeArray($array);

        // Convert back to object recursively
        return json_decode(json_encode($sanitized));
    }

    /**
     * Check if a field should be excluded.
     */
    protected function shouldExcludeField(string $field): bool
    {
        $field = strtolower($field);
        $snakeField = Str::snake($field);

        foreach ($this->excludeFields as $excludeField) {
            $excludeField = strtolower($excludeField);
            $excludeSnake = Str::snake($excludeField);

            // Exact match (case-insensitive)
            if ($field === $excludeField || $snakeField === $excludeSnake) {
                return true;
            }

            // Check if field contains the exclude pattern
            if (str_contains($field, $excludeField) || str_contains($snakeField, $excludeSnake)) {
                return true;
            }

            // Check for camelCase to snake_case conversion match
            if ($snakeField === $excludeField || $field === str_replace('_', '', $excludeField)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a field should be masked.
     */
    protected function shouldMaskField(string $field): bool
    {
        $field = strtolower($field);
        $snakeField = Str::snake($field);

        foreach ($this->maskFields as $maskField) {
            $maskField = strtolower($maskField);
            $maskSnake = Str::snake($maskField);

            // Exact match (case-insensitive)
            if ($field === $maskField || $snakeField === $maskSnake) {
                return true;
            }

            // Check if field contains the mask pattern
            if (str_contains($field, $maskField) || str_contains($snakeField, $maskSnake)) {
                return true;
            }

            // Check for camelCase to snake_case conversion match
            if ($snakeField === $maskField || $field === str_replace('_', '', $maskField)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask a value based on the configured strategy.
     */
    protected function maskValue(mixed $value): mixed
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $this->redactedPlaceholder;
        }

        $stringValue = (string) $value;

        return match ($this->maskingStrategy) {
            'full' => str_repeat($this->maskCharacter, strlen($stringValue)),
            'hash' => substr(hash('sha256', $stringValue), 0, 16),
            'partial' => $this->partialMask($stringValue),
            default => $this->partialMask($stringValue),
        };
    }

    /**
     * Apply partial masking to a value.
     */
    protected function partialMask(string $value): string
    {
        $length = strlen($value);

        // For short values, mask everything
        if ($length <= 4) {
            return str_repeat($this->maskCharacter, $length);
        }

        // Check if it looks like an email
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->maskEmail($value);
        }

        // Check if it looks like a phone number
        if (preg_match('/^[\d\s\-\+\(\)]+$/', $value) && $length >= 10) {
            return $this->maskPhone($value);
        }

        // Check if it looks like a token (Bearer token, JWT, etc.)
        if (str_starts_with($value, 'Bearer ')) {
            return 'Bearer '.str_repeat($this->maskCharacter, 8);
        }

        // Default partial masking: show first and last 2 characters
        $visibleChars = 2;

        $start = substr($value, 0, $visibleChars);
        $end = substr($value, -$visibleChars);
        $middle = str_repeat($this->maskCharacter, max(0, $length - ($visibleChars * 2)));

        return $start.$middle.$end;
    }

    /**
     * Mask an email address.
     */
    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return str_repeat($this->maskCharacter, strlen($email));
        }

        [$local, $domain] = $parts;

        // Mask local part
        $localLength = strlen($local);
        if ($localLength <= 2) {
            $maskedLocal = str_repeat($this->maskCharacter, $localLength);
        } else {
            $maskedLocal = substr($local, 0, 2).str_repeat($this->maskCharacter, $localLength - 2);
        }

        // Mask domain
        $domainParts = explode('.', $domain);
        if (count($domainParts) >= 2) {
            $domainName = $domainParts[0];
            $tld = implode('.', array_slice($domainParts, 1));

            $domainLength = strlen($domainName);
            if ($domainLength <= 2) {
                $maskedDomain = str_repeat($this->maskCharacter, $domainLength).'.'.$tld;
            } else {
                $maskedDomain = str_repeat($this->maskCharacter, $domainLength).'.'.$tld;
            }
        } else {
            $maskedDomain = str_repeat($this->maskCharacter, strlen($domain));
        }

        return $maskedLocal.'@'.$maskedDomain;
    }

    /**
     * Mask a phone number.
     */
    protected function maskPhone(string $phone): string
    {
        // Remove non-numeric characters for processing
        $digitsOnly = preg_replace('/[^\d]/', '', $phone);

        if ($digitsOnly === null || strlen($digitsOnly) < 10) {
            return str_repeat($this->maskCharacter, strlen($phone));
        }

        // Keep last 4 digits visible
        $visibleDigits = substr($digitsOnly, -4);
        $maskedPart = str_repeat($this->maskCharacter, strlen($digitsOnly) - 4);

        // Try to preserve the original format
        if (preg_match('/^(\+\d{1,3})?[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{4}$/', $phone)) {
            // US format: +1 (XXX) XXX-1234
            return preg_replace('/\d/', $this->maskCharacter, substr($phone, 0, -4)).$visibleDigits;
        }

        return $maskedPart.'-'.$visibleDigits;
    }

    /**
     * Add a field to be excluded.
     */
    public function addExcludeField(string $field): void
    {
        if (! in_array($field, $this->excludeFields, true)) {
            $this->excludeFields[] = $field;
        }
    }

    /**
     * Add a field to be masked.
     */
    public function addMaskField(string $field): void
    {
        if (! in_array($field, $this->maskFields, true)) {
            $this->maskFields[] = $field;
        }
    }

    /**
     * Add a header to be excluded.
     */
    public function addExcludeHeader(string $header): void
    {
        $header = strtolower($header);
        if (! in_array($header, $this->excludeHeaders, true)) {
            $this->excludeHeaders[] = $header;
        }
    }

    /**
     * Set the masking strategy.
     */
    public function setMaskingStrategy(string $strategy): void
    {
        if (in_array($strategy, ['partial', 'full', 'hash'], true)) {
            $this->maskingStrategy = $strategy;
        }
    }

    /**
     * Get the current configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return [
            'exclude_fields' => $this->excludeFields,
            'mask_fields' => $this->maskFields,
            'masking_strategy' => $this->maskingStrategy,
            'exclude_headers' => $this->excludeHeaders,
        ];
    }
}
