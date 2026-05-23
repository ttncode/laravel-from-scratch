<?php

namespace Framework\Foundation\Bootstrap;

use Framework\Config\Repository;
use Framework\Foundation\Application;
use Framework\Contracts\Config\Repository as RepositoryContract;

class LoadConfiguration
{
    /**
     * Bootstrap the given application.
     *
     * @param Application  $app
     * @return void
     */
    public function bootstrap(Application $app): void
    {
        // Next we will spin through all of the configuration files in the configuration
        // directory and load each one into the repository. This will make all of the
        // options available to the developer for use in various parts of this app.
        $app->instance('config', $config = new Repository());

        $this->loadConfigurationFiles($app, $config);
    }

    /**
     * Load the configuration items from all of the files.
     * 
     * @param Application $app
     * @param RepositoryContract $repository
     * @return void
     */
    protected function loadConfigurationFiles(Application $app, RepositoryContract $repository): void
    {
        $configPath = $app->configPath();

        if (is_dir($configPath)) {
            $files = glob($configPath . '/*.php');

            foreach ($files as $file) {
                $key = basename($file, '.php');
                $repository->set($key, require $file);
            }
        }
    }
}
