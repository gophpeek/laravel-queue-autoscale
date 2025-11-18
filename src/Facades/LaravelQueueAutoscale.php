<?php

namespace PHPeek\LaravelQueueAutoscale\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \PHPeek\LaravelQueueAutoscale\LaravelQueueAutoscale
 */
class LaravelQueueAutoscale extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PHPeek\LaravelQueueAutoscale\LaravelQueueAutoscale::class;
    }
}
