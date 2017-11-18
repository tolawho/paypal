<?php

namespace Tolawho\Paypal\Providers;

use Illuminate\Support\ServiceProvider as Provider;

class ServiceProvider extends Provider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {

        $this->_loadConfiguration();

    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->_registerPaypal();
    }

    /**
     * Load the configuration files and allow them to be published.
     *
     * @author tolawho
     * @return void
     */
    private function _loadConfiguration()
    {
        $configPath = __DIR__ . '/config.php';

        $this->publishes([$configPath => config_path('paypal.php')], 'config');

        $this->mergeConfigFrom($configPath, 'paypal');
    }

    /**
     * Register package
     *
     * @return void
     */
    private function _registerPaypal()
    {
        $this->app->bind('paypal', 'Tolawho\Paypal\Services\Paypal');
    }
}
