<?php

namespace Baum\Providers;

use Baum\Console\InstallCommand;
use Baum\Generators\MigrationGenerator;
use Baum\Generators\ModelGenerator;
use Illuminate\Support\ServiceProvider;

class BaumServiceProvider extends ServiceProvider
{
    public function register()
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->registerInstallCommand();

        // Resolve the commands with Artisan by attaching the event listener to Artisan's
        // startup. This allows us to use the commands from our terminal.
        $this->commands('command.baum.install');
    }

    protected function registerInstallCommand(): void
    {
        $this->app->singleton('command.baum.install', function ($app) {
            $migrator = new MigrationGenerator($app['files']);
            $modeler = new ModelGenerator($app['files']);

            return new InstallCommand($migrator, $modeler);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['command.baum.install'];
    }
}
