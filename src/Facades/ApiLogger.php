<?php

namespace Ameax\ApiLogger\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ameax\ApiLogger\ApiLogger
 */
class ApiLogger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ameax\ApiLogger\ApiLogger::class;
    }
}
