<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class JsonCast implements CastsAttributes
{
    /**
     * @param  Model  $model
     * @return array<mixed>|null
     */
    public function get($model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true);
    }

    /**
     * @param  Model  $model
     * @param  array<mixed>|null  $value
     */
    public function set($model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
