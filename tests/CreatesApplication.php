<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $app = require __DIR__ . '/../bootstrap/app.php';

        // 強制 Laravel 載入 `.env.testing`
        $app->loadEnvironmentFrom('.env.testing');

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
