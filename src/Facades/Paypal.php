<?php

namespace Tolawho\Paypal\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class Paypal
 * @package Tolawho\Paypal\Facades
 */
class Paypal extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'paypal';
    }
}
