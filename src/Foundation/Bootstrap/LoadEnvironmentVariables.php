<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Foundation\Application;

class LoadEnvironmentVariables
{
    /**
     * Bootstrap the given application.
     *
     * @param Application  $app
     * @return void
     */
    public function bootstrap(Application $app): void
    {
        // Load environment variables
        $envPath = $app->basePath('.env');
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) {
                    continue; // Skip comments
                }

                [$key, $value] = explode('=', $line, 2);
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }
}
